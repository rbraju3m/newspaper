<?php

namespace Tests\Feature;

use App\Services\ErrorAlerter;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Error alerting: what gets recorded, what gets pushed, and — mostly — what
 * does not.
 *
 * The failure mode this guards against is not "no alert arrives". It is a
 * thousand alerts arriving, which is indistinguishable from silence and takes
 * the mail server with it.
 *
 * No database: the alerter deliberately does not touch one.
 */
class ErrorAlertTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Its own log file per test, so a run does not append to the real one.
        $this->logPath = storage_path('framework/testing/errors-'.getmypid().'.log');
        @mkdir(dirname($this->logPath), 0777, true);

        config([
            'logging.channels.errors.path' => $this->logPath,
            'errors.alert.email' => 'oncall@newsroom.example',
            'errors.alert.webhook' => 'https://hooks.slack.example/abc',
            'errors.throttle_minutes' => 60,
            'errors.max_per_hour' => 20,
            'errors.ignore' => [],
        ]);

        Log::forgetChannel('errors');   // the manager caches resolved channels
        Cache::store('file')->flush();

        Mail::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->logPath).'/errors-*.log') ?: [] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function alerter(): ErrorAlerter
    {
        return app(ErrorAlerter::class);
    }

    /** @return list<array<string, mixed>> */
    private function recorded(): array
    {
        $file = dirname($this->logPath).'/'.pathinfo($this->logPath, PATHINFO_FILENAME).'-'.now()->format('Y-m-d').'.log';

        if (! is_file($file)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        )));
    }

    // -----------------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------------

    public function test_every_reported_exception_is_recorded_as_json(): void
    {
        $this->alerter()->report(new RuntimeException('গোলমাল'));

        $records = $this->recorded();

        $this->assertCount(1, $records);
        $this->assertSame('গোলমাল', $records[0]['message']);
        $this->assertSame(RuntimeException::class, $records[0]['context']['type']);
        $this->assertNotEmpty($records[0]['context']['fingerprint']);
    }

    /** Recording is unconditional; alerting is what the channels gate. */
    public function test_it_records_even_with_no_channel_configured(): void
    {
        config(['errors.alert.email' => null, 'errors.alert.webhook' => null]);

        $this->alerter()->report(new RuntimeException('unheard'));

        $this->assertCount(1, $this->recorded());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Throttling — the part that decides whether this feature is usable
    // -----------------------------------------------------------------------

    public function test_a_repeated_fault_alerts_once_and_is_recorded_every_time(): void
    {
        $report = fn () => $this->alerter()->report(new RuntimeException('the same thing'));

        for ($i = 0; $i < 5; $i++) {
            $report();
        }

        $this->assertCount(5, $this->recorded(), 'every occurrence must reach the log');
        Mail::assertSentCount(1);
        Http::assertSentCount(1);
    }

    public function test_a_different_fault_alerts_separately(): void
    {
        $this->alerter()->report(new RuntimeException('one'));
        $this->alerter()->report(new LogicException('two'));

        Mail::assertSentCount(2);
    }

    /**
     * The message is not part of the fingerprint. A loop failing on 400
     * different ids is one fault, not 400.
     */
    public function test_the_fingerprint_ignores_the_message(): void
    {
        $alerter = $this->alerter();

        $line = fn (string $message) => new RuntimeException($message);

        $this->assertSame(
            $alerter->fingerprint($line('article 1 missing')),
            $alerter->fingerprint($line('article 2 missing')),
        );
    }

    /**
     * A fault with many distinct fingerprints must not defeat the throttle — a
     * failed migration produces a different error on every page.
     *
     * Each `new` sits on its own line on purpose: the fingerprint is class,
     * file and line, so a loop would produce one fingerprint rather than five
     * and the test would pass without exercising the cap at all.
     */
    public function test_the_hourly_cap_holds_across_fingerprints(): void
    {
        config(['errors.max_per_hour' => 3]);

        $faults = [
            new RuntimeException('a'),
            new RuntimeException('b'),
            new RuntimeException('c'),
            new RuntimeException('d'),
            new RuntimeException('e'),
        ];

        $this->assertCount(5, array_unique(array_map(
            fn ($e) => $this->alerter()->fingerprint($e), $faults
        )), 'the fixture must produce five distinct fingerprints');

        foreach ($faults as $fault) {
            $this->alerter()->report($fault);
        }

        Mail::assertSentCount(3);
        $this->assertCount(5, $this->recorded(), 'the cap silences alerts, never the log');
    }

    // -----------------------------------------------------------------------
    // What must never alert
    // -----------------------------------------------------------------------

    /**
     * Laravel declines to report these before a reportable callback is
     * reached. Asserted through the real handler, because that filtering is
     * the framework's and not ours.
     */
    public function test_the_framework_never_reports_ordinary_web_noise(): void
    {
        $handler = app(ExceptionHandler::class);

        $handler->report(new NotFoundHttpException('missing'));
        $handler->report(ValidationException::withMessages(['x' => 'nope']));

        $this->assertSame([], $this->recorded());
        Mail::assertNothingSent();
    }

    public function test_the_ignore_list_suppresses_the_alert_but_not_the_record(): void
    {
        config(['errors.ignore' => [LogicException::class]]);

        $this->alerter()->report(new LogicException('expected'));

        $this->assertCount(1, $this->recorded());
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // It must never throw
    // -----------------------------------------------------------------------

    public function test_a_failing_webhook_does_not_escape(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->alerter()->report(new RuntimeException('still reportable'));

        // Reaching here at all is the assertion: a throw would have surfaced
        // inside the exception handler, replacing the real error with this one.
        $this->assertCount(1, $this->recorded());
        Mail::assertSentCount(1);
    }

    public function test_a_failing_mailer_does_not_escape(): void
    {
        // Replaces the fake outright: the point is a transport that throws.
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));

        $this->alerter()->report(new RuntimeException('still reportable'));

        $this->assertCount(1, $this->recorded());
        Http::assertSentCount(1);   // the other channel still went out
    }

    // -----------------------------------------------------------------------
    // Webhook shape
    // -----------------------------------------------------------------------

    public function test_slack_gets_text_and_discord_gets_content(): void
    {
        $this->alerter()->report(new RuntimeException('for slack'));

        Http::assertSent(fn ($request) => array_key_exists('text', $request->data()));

        Cache::store('file')->flush();
        config(['errors.alert.webhook' => 'https://discord.com/api/webhooks/1/abc']);

        $this->alerter()->report(new LogicException('for discord'));

        Http::assertSent(fn ($request) => array_key_exists('content', $request->data()));
    }

    public function test_the_alert_body_names_the_fault_and_the_fingerprint(): void
    {
        $this->alerter()->report(new RuntimeException('database has gone away'));

        Http::assertSent(function ($request) {
            $text = $request->data()['text'];

            return str_contains($text, 'RuntimeException')
                && str_contains($text, 'database has gone away')
                && str_contains($text, 'fingerprint:');
        });
    }

    // -----------------------------------------------------------------------
    // errors:digest
    // -----------------------------------------------------------------------

    public function test_the_digest_groups_occurrences_and_counts_them(): void
    {
        $report = fn () => $this->alerter()->report(new RuntimeException('recurring trouble'));

        for ($i = 0; $i < 4; $i++) {
            $report();
        }

        $this->alerter()->report(new LogicException('a second problem'));

        // Artisan::call rather than $this->artisan(): expectsOutputToContain
        // registers one Mockery expectation per substring, and a single write
        // satisfies only the first of them, so two substrings on one line can
        // never both match. Capturing the output avoids the whole question.
        $this->assertSame(0, Artisan::call('errors:digest'));

        $output = Artisan::output();

        $this->assertStringContainsString('5 error(s)', $output);
        $this->assertStringContainsString('2 distinct', $output);
        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertStringContainsString('recurring trouble', $output);
        $this->assertStringContainsString('4 ×', $output, 'the repeated fault must show its count');
    }

    /** A log truncated mid-write must not take the digest down with it. */
    public function test_the_digest_skips_a_malformed_line(): void
    {
        $this->alerter()->report(new RuntimeException('valid record'));

        $file = dirname($this->logPath).'/'.pathinfo($this->logPath, PATHINFO_FILENAME).'-'.now()->format('Y-m-d').'.log';
        file_put_contents($file, '{"message":"half a line'."\n", FILE_APPEND);

        $this->assertSame(0, Artisan::call('errors:digest'));

        $this->assertStringContainsString('1 error(s)', Artisan::output());
    }

    public function test_the_digest_says_so_when_nothing_broke(): void
    {
        $this->artisan('errors:digest')->assertSuccessful();

        $this->assertSame([], $this->recorded());
    }

    public function test_the_digest_can_be_mailed_instead_of_printed(): void
    {
        $this->alerter()->report(new RuntimeException('for the digest'));

        $this->assertSame(0, Artisan::call('errors:digest --email=oncall@newsroom.example'));

        $output = Artisan::output();

        $this->assertStringContainsString('oncall@newsroom.example', $output);
        $this->assertStringNotContainsString('for the digest', $output, 'the body goes to the mail, not the terminal');
    }
}
