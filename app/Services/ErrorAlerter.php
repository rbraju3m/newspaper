<?php

namespace App\Services;

use App\Mail\ErrorAlert;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns an unhandled exception into something somebody actually hears about.
 *
 * Everything reportable is written to the `errors` channel as one JSON object
 * per line — that is what `errors:digest` reads, and it happens whether or not
 * any alert channel is configured. On top of that, the first occurrence of a
 * given fault pushes an alert to email, a webhook, or both.
 *
 * Three rules govern the pushing, and each of them is here because the naive
 * version of this feature is worse than not having it:
 *
 *  - **It must never throw.** An exception raised while reporting an exception
 *    is a loop, and the loop is inside the error handler. Every path here is
 *    wrapped, and the fallback is a plain log line.
 *  - **It must not flood.** One alert per fingerprint per hour, and a hard
 *    ceiling across all fingerprints. A broken page must not send a thousand
 *    emails, because a thousand emails is indistinguishable from silence.
 *  - **It must not depend on the database.** Throttle state lives in the file
 *    cache. A database outage is the failure you most need to hear about, and
 *    it is exactly when the database cannot tell you anything.
 *
 * Alerts go out synchronously, because this deployment runs no queue worker.
 * The timeouts are short for that reason. If a worker ever exists, dispatching
 * from here is the upgrade.
 */
class ErrorAlerter
{
    public function report(Throwable $e): void
    {
        try {
            $context = $this->context($e);

            Log::channel('errors')->error($e->getMessage(), $context);

            if ($this->shouldAlert($e, $context['fingerprint'])) {
                $this->send($e, $context);
            }
        } catch (Throwable $failure) {
            // Last resort. Never rethrow: this runs inside the exception
            // handler, and the handler has nowhere to send its own failure.
            $this->fallback($failure);
        }
    }

    /**
     * The identity of a fault, for throttling.
     *
     * Class, file and line — deliberately not the message, which usually
     * carries an id or a value and would make the same broken line look like a
     * new problem on every request.
     */
    public function fingerprint(Throwable $e): string
    {
        return substr(sha1($e::class.'|'.$e->getFile().'|'.$e->getLine()), 0, 12);
    }

    /** @return array<string, mixed> */
    private function context(Throwable $e): array
    {
        $context = [
            'fingerprint' => $this->fingerprint($e),
            'type' => $e::class,
            'file' => $e->getFile().':'.$e->getLine(),
        ];

        if (app()->runningInConsole()) {
            return $context + ['source' => 'console', 'command' => implode(' ', array_slice($_SERVER['argv'] ?? [], 1))];
        }

        $request = request();

        return $context + [
            'source' => 'http',
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'user_id' => $this->userId(),
        ];
    }

    /** Auth may not be resolvable at all, depending on where this fired. */
    private function userId(): ?int
    {
        try {
            return Auth::id();
        } catch (Throwable) {
            return null;
        }
    }

    private function shouldAlert(Throwable $e, string $fingerprint): bool
    {
        foreach ((array) config('errors.ignore', []) as $ignored) {
            if ($e instanceof $ignored) {
                return false;
            }
        }

        if (! $this->hasSomewhereToSend()) {
            return false;
        }

        $cache = Cache::store(config('errors.cache_store', 'file'));

        // add() is atomic: it returns false when the key already exists, which
        // is the whole throttle. A get-then-put would race two simultaneous
        // requests into two alerts.
        $fresh = $cache->add(
            'error-alert:'.$fingerprint,
            true,
            now()->addMinutes(max(1, (int) config('errors.throttle_minutes', 60))),
        );

        if (! $fresh) {
            return false;
        }

        return $this->withinHourlyCap($cache);
    }

    private function hasSomewhereToSend(): bool
    {
        return filled(config('errors.alert.email')) || filled(config('errors.alert.webhook'));
    }

    /**
     * A ceiling across every fingerprint. A fault that produces many distinct
     * errors — a failed migration, a missing table — would otherwise defeat
     * the per-fingerprint throttle entirely.
     */
    private function withinHourlyCap($cache): bool
    {
        $cap = max(1, (int) config('errors.max_per_hour', 20));
        $key = 'error-alert:hour:'.now()->format('YmdH');

        $sent = (int) $cache->get($key, 0);

        if ($sent >= $cap) {
            return false;
        }

        $cache->put($key, $sent + 1, now()->addHour());

        return true;
    }

    /** @param  array<string, mixed>  $context */
    private function send(Throwable $e, array $context): void
    {
        if (filled($address = config('errors.alert.email'))) {
            $this->email($e, $context, $address);
        }

        if (filled($webhook = config('errors.alert.webhook'))) {
            $this->webhook($e, $context, $webhook);
        }
    }

    /** @param  array<string, mixed>  $context */
    private function email(Throwable $e, array $context, string $address): void
    {
        try {
            Mail::to($address)->send(new ErrorAlert(
                type: $e::class,
                summary: $this->summary($e, $context),
                trace: $this->trace($e),
            ));
        } catch (Throwable $failure) {
            $this->fallback($failure, 'email');
        }
    }

    /** @param  array<string, mixed>  $context */
    private function webhook(Throwable $e, array $context, string $url): void
    {
        try {
            // Slack reads `text`; Discord reads `content`. Sending the field
            // the host does not expect is how you get a silent 400.
            $field = Str::contains($url, ['discord.com', 'discordapp.com']) ? 'content' : 'text';

            Http::timeout(max(1, (int) config('errors.alert.webhook_timeout', 5)))
                ->post($url, [$field => $this->summary($e, $context)])
                ->throw();
        } catch (Throwable $failure) {
            $this->fallback($failure, 'webhook');
        }
    }

    /** @param  array<string, mixed>  $context */
    private function summary(Throwable $e, array $context): string
    {
        $lines = [
            $e::class,
            $e->getMessage(),
            '',
            'at '.$context['file'],
        ];

        if (($context['source'] ?? null) === 'http') {
            $lines[] = $context['method'].' '.$context['url'];
            $lines[] = 'user: '.($context['user_id'] ?? 'guest').'   ip: '.$context['ip'];
        } else {
            $lines[] = 'artisan '.($context['command'] ?? '');
        }

        $lines[] = 'fingerprint: '.$context['fingerprint']
            .'   (silenced for '.config('errors.throttle_minutes').' minutes)';

        return implode("\n", $lines);
    }

    /** Enough frames to locate it, not the whole novel. */
    private function trace(Throwable $e): string
    {
        return implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 15));
    }

    private function fallback(Throwable $failure, ?string $channel = null): void
    {
        try {
            Log::channel('single')->error(
                'ErrorAlerter'.($channel ? " ({$channel})" : '').' failed: '.$failure->getMessage()
            );
        } catch (Throwable) {
            // Nothing left to try. Swallowing here is the only correct move:
            // rethrowing would replace a real error with this one.
        }
    }
}
