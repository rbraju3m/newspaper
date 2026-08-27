<?php

namespace App\Console\Commands;

use App\Mail\NewsletterDigest;
use App\Models\NewsletterSubscriber;
use App\Services\NewsletterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mails one edition of the newsletter.
 *
 * Sent inline rather than queued, because this deployment runs no queue worker
 * — the same reason `ErrorAlerter` sends from the exception handler. That is
 * fine here in a way it would not be in a request: this is a cron process, and
 * blocking is what a cron process is for.
 *
 * Three properties that matter more than speed:
 *
 * **A quiet news day sends nothing.** `NewsletterService::editionFor()` coming
 * back empty means that reader is skipped entirely. A newsletter that arrives
 * every morning whether or not anything happened is a newsletter that gets
 * filtered, and after that none of them arrive.
 *
 * **One bad address cannot end the run.** Every send is caught individually.
 * An SMTP rejection on row 40 of 4,000 must not mean 3,960 readers go without.
 *
 * **`last_sent_at` is stamped per subscriber, as each one succeeds.** Not in a
 * batch at the end: a run that dies halfway must leave the half that received
 * it marked as having received it, or a re-run mails them twice.
 */
class SendNewsletter extends Command
{
    protected $signature = 'newsletter:send
        {--frequency=daily : Which edition to send — daily or weekly}
        {--dry-run : Build the edition and report the audience, send nothing}
        {--limit= : Stop after this many subscribers}
        {--to= : Send only to this address, ignoring the send list — for checking the template}';

    protected $description = 'Send the newsletter digest to verified subscribers';

    public function handle(NewsletterService $newsletter): int
    {
        $frequency = (string) $this->option('frequency');

        if (! NewsletterService::isFrequency($frequency)) {
            $this->components->error('Unknown frequency: '.$frequency
                .'. Expected one of '.implode(', ', NewsletterService::frequencies()).'.');

            return self::FAILURE;
        }

        $since = $newsletter->since($frequency);
        $dry = (bool) $this->option('dry-run');

        $query = $this->recipients($frequency, $since);
        $audience = $query->count();

        $this->components->twoColumnDetail('Edition', $frequency);
        $this->components->twoColumnDetail('Covering since', $since->toDayDateTimeString());
        $this->components->twoColumnDetail('Recipients', number_format($audience));

        if ($audience === 0) {
            $this->components->info('Nobody is due this edition.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(200, function ($subscribers) use (
            $newsletter, $frequency, $dry, &$sent, &$skipped, &$failed
        ) {
            foreach ($subscribers as $subscriber) {
                $articles = $newsletter->editionFor($subscriber, $frequency);

                // Nothing published in their sections this window. Skipped, and
                // deliberately *not* stamped: they are still due whenever there
                // is something to say.
                if ($articles->isEmpty()) {
                    $skipped++;

                    continue;
                }

                if ($dry) {
                    $sent++;

                    continue;
                }

                $this->deliver($subscriber, $articles, $frequency, $newsletter)
                    ? $sent++
                    : $failed++;
            }
        });

        if ($dry) {
            $this->preview($newsletter, $frequency);
            $this->components->info("Dry run — {$sent} would receive an edition, {$skipped} have no stories.");

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Sent', (string) $sent);
        $this->components->twoColumnDetail('Skipped (no stories for them)', (string) $skipped);
        $this->components->twoColumnDetail('Failed', (string) $failed);

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<NewsletterSubscriber>
     */
    private function recipients(string $frequency, \DateTimeInterface $since)
    {
        // `--to` is for checking what the thing actually looks like in a real
        // inbox. It ignores frequency and last_sent_at but *not* verification:
        // there is no reason to be able to mail an address that never confirmed.
        if ($address = $this->option('to')) {
            return NewsletterSubscriber::query()->active()->where('email', $address);
        }

        return NewsletterSubscriber::query()->dueFor($frequency, $since);
    }

    private function deliver(
        NewsletterSubscriber $subscriber,
        $articles,
        string $frequency,
        NewsletterService $newsletter,
    ): bool {
        try {
            Mail::to($subscriber->email)->send(new NewsletterDigest(
                $subscriber,
                $articles,
                $frequency,
                $newsletter->subject($frequency, $articles),
            ));

            // Stamped the moment it succeeds, not at the end of the run: a run
            // that dies halfway must not re-mail the half that received it.
            $subscriber->forceFill(['last_sent_at' => now()])->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('Newsletter send failed', [
                'subscriber' => $subscriber->id,
                'error' => $e->getMessage(),
            ]);

            $this->components->warn($subscriber->email.': '.$e->getMessage());

            return false;
        }
    }

    /** What the general edition holds, so a dry run is worth reading. */
    private function preview(NewsletterService $newsletter, string $frequency): void
    {
        // An empty category list is "everything", which is what most
        // subscribers are — so this is the edition the majority receive.
        $articles = $newsletter->editionFor(new NewsletterSubscriber(['categories' => []]), $frequency);

        $this->line('');
        $this->components->twoColumnDetail('<options=bold>Subject</>', $newsletter->subject($frequency, $articles));

        foreach ($articles as $i => $article) {
            $this->components->twoColumnDetail('  '.($i + 1), $article->title);
        }

        $this->line('');
    }
}
