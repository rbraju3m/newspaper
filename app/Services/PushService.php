<?php

namespace App\Services;

use App\Models\Article;
use App\Models\PushSubscription;
use App\Support\PushResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Sends Web Push notifications, and keeps the subscription table honest.
 *
 * Two things shape this class.
 *
 * **A push cannot be recalled.** There is no delete, no edit and no read
 * receipt; the moment `flush()` returns, whatever was sent is on somebody's
 * lock screen. So the guards live in front of the send — `configured()`, the
 * `push_sent_at` stamp `Article` carries, and the fact that nothing here is
 * wired to a model event. Every send is something a person asked for.
 *
 * **A dead subscription must actually be deleted.** A push service answering
 * 404 or 410 is telling us the browser is gone — uninstalled, permission
 * revoked, profile wiped — and continuing to send to it is what gets a sender
 * rate-limited or blocked outright. `isSubscriptionExpired()` is the only
 * failure this treats as routine, and the row goes immediately.
 */
class PushService
{
    /**
     * Both halves of the key pair, or the feature is off.
     *
     * Off rather than broken is deliberate: an application deployed without
     * keys should not show a reader a toggle that cannot work, and should not
     * fail a request that happens to touch the subscribe endpoint.
     */
    public function configured(): bool
    {
        return filled(config('push.public_key')) && filled(config('push.private_key'));
    }

    /** The key the browser needs to call `pushManager.subscribe()`. */
    public function publicKey(): ?string
    {
        return $this->configured() ? config('push.public_key') : null;
    }

    /**
     * The notification an article makes.
     *
     * The keys are the contract `public/sw.js` reads, and changing one here
     * without changing it there produces a notification that shows the
     * fallback title on every device that has not updated its worker.
     *
     * `tag` is the article id so a correction to a running story replaces the
     * earlier notification instead of stacking a second one beside it.
     */
    public function payloadFor(Article $article): array
    {
        // `Article::url` reads `category->path`, which is a lazy load under
        // strict mode. Loading it here rather than asking every caller to
        // remember keeps the one that forgets from throwing on the line after
        // the notification has already been queued.
        $article->loadMissing('category');

        return [
            'title' => $article->title,
            // 140 including the ellipsis. Android shows about two lines on a
            // lock screen and truncates the rest itself; what matters is that
            // the useful half is in front of wherever the phone cuts it.
            'body' => str($article->excerpt ?: strip_tags($article->body))->squish()->limit(139, '…')->value(),
            'url' => $article->url,
            'icon' => $article->image_url ?: asset('images/icon-192.png'),
            'tag' => 'article-'.$article->id,
        ];
    }

    /**
     * Sends one payload to every subscription the query matches.
     *
     * Chunked by id rather than loaded whole: the table is one row per browser
     * install, so it is the one table here that grows with readership rather
     * than with what the newsroom publishes.
     */
    public function send(array $payload, ?Builder $query = null): PushResult
    {
        if (! $this->configured()) {
            return new PushResult(reasons: ['push is not configured']);
        }

        $query ??= PushSubscription::query()->forBreaking();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $result = new PushResult;

        $query->orderBy('id')->chunkById(
            max(1, (int) config('push.batch')),
            function (Collection $chunk) use (&$result, $body) {
                $result = $result->plus($this->flush($chunk, $body));
            },
        );

        return $result;
    }

    /**
     * One batch: queue every subscription, flush, then act on the reports.
     *
     * A fresh `WebPush` per batch rather than one for the whole run — the
     * library accumulates queued notifications on the instance, and reusing it
     * across chunks would re-send earlier batches.
     *
     * @param  Collection<int, PushSubscription>  $chunk
     */
    private function flush(Collection $chunk, string $body): PushResult
    {
        $push = $this->client();
        $unusable = 0;

        foreach ($chunk as $subscription) {
            try {
                $push->queueNotification(Subscription::create($subscription->toWebPush()), $body);
            } catch (\Throwable $e) {
                // A malformed row — a truncated key, an encoding the library
                // does not know — would otherwise abort the whole batch. It is
                // as undeliverable as an expired one, so it goes the same way.
                $this->prune($subscription->endpoint, 'unusable subscription: '.$e->getMessage());
                $unusable++;
            }
        }

        $delivered = [];
        $failed = 0;
        $pruned = $unusable;
        $reasons = [];

        foreach ($push->flush() as $report) {
            $endpoint = $report->getEndpoint();

            if ($report->isSuccess()) {
                $delivered[] = $endpoint;

                continue;
            }

            // The push service says this browser is gone. Not an error — the
            // correct response is to stop having the row.
            if ($report->isSubscriptionExpired()) {
                $this->prune($endpoint, 'expired');
                $pruned++;

                continue;
            }

            $failed++;
            $reasons[] = $report->getReason();
        }

        // Only the ones that actually arrived. Stamping the whole batch would
        // make `last_success_at` mean "was attempted", which is the opposite
        // of what anybody pruning a stale table would read it as.
        if ($delivered !== []) {
            PushSubscription::whereIn('endpoint', $delivered)
                ->update(['last_success_at' => now()]);
        }

        return new PushResult(count($delivered), $failed, $pruned, array_values(array_unique($reasons)));
    }

    private function client(): WebPush
    {
        $push = new WebPush(
            ['VAPID' => [
                'subject' => (string) config('push.subject'),
                'publicKey' => (string) config('push.public_key'),
                'privateKey' => (string) config('push.private_key'),
            ]],
            [
                'TTL' => (int) config('push.ttl'),
                // Breaking news is the one thing that justifies waking a
                // screen; anything less urgent should not be a push at all.
                'urgency' => 'high',
            ],
            $this->httpClient(),
            null,
            null,
            null,
            // Not optional. `Utils::checkRequirement()` runs in the constructor
            // and reports a missing GMP/BCMath with `trigger_error(E_USER_NOTICE)`
            // when it has no logger — and Laravel's handler turns an E_USER_NOTICE
            // into an ErrorException, so on a box without either extension
            // *constructing* this class is a 500. With a logger it is a line in
            // the log, which is what it was always meant to be.
            Log::getLogger(),
        );

        // The VAPID header is signed per push service, not per message, so
        // reusing it across a batch is one signature instead of thousands.
        $push->setReuseVAPIDHeaders(true);

        return $push;
    }

    /**
     * A client with a timeout on it.
     *
     * The library otherwise discovers one with whatever defaults it ships, and
     * a push service that accepts a connection and then stops talking would
     * hold the batch — and the request or command behind it — open indefinitely.
     *
     * A bound `ClientInterface` wins. That is the seam the tests use to put a
     * fake push service in front of this: there is no `Push::fake()` to reach
     * for, and a test that sends for real would be a test that posts to
     * Google.
     */
    private function httpClient(): ?ClientInterface
    {
        if (app()->bound(ClientInterface::class)) {
            return app(ClientInterface::class);
        }

        if (! class_exists(\GuzzleHttp\Client::class)) {
            return null;
        }

        return new \GuzzleHttp\Client([
            'timeout' => (int) config('push.timeout'),
            'connect_timeout' => 5,
        ]);
    }

    private function prune(string $endpoint, string $why): void
    {
        PushSubscription::where('endpoint', $endpoint)->delete();

        Log::info('Push subscription removed', ['reason' => $why]);
    }
}
