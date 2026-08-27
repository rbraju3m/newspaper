<?php

namespace App\Console\Commands;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Console\Command;

/**
 * Sends one article to every subscribed browser as a breaking-news alert.
 *
 * Deliberately a command an operator runs and a button an editor presses,
 * never a model event. `is_breaking` is a display flag — it drives the ticker,
 * and an editor toggles it while writing — and wiring a notification to every
 * reader onto a checkbox is how a typo becomes a thing you cannot take back.
 *
 * `--dry-run` reports the audience and the exact payload and sends nothing,
 * which is the only way to see what a notification will actually say before
 * several thousand people do.
 */
class SendPushAlert extends Command
{
    protected $signature = 'push:send
        {article : Id of the article to alert on}
        {--dry-run : Show the audience and the payload, send nothing}
        {--force : Send again even though this article has already been alerted}';

    protected $description = 'Send a breaking-news push notification for an article';

    public function handle(PushService $push): int
    {
        if (! $push->configured()) {
            $this->components->error('Push is not configured — set PUSH_PUBLIC_KEY and PUSH_PRIVATE_KEY.');
            $this->line('  <options=bold>php artisan push:keys</> generates a pair.');

            return self::FAILURE;
        }

        $article = Article::with('category')->find($this->argument('article'));

        if (! $article) {
            $this->components->error('No article with id '.$this->argument('article').'.');

            return self::FAILURE;
        }

        // A notification is a link. Sending one to a story a reader cannot open
        // is worse than sending nothing: it is a push straight to a 404.
        if ($article->status !== ArticleStatus::Published) {
            $this->components->error('That article is not published — a notification would link to a 404.');

            return self::FAILURE;
        }

        if ($article->push_sent_at && ! $this->option('force')) {
            $this->components->error('An alert already went out for this article on '
                .$article->push_sent_at->toDayDateTimeString().'.');
            $this->line('  Re-run with <options=bold>--force</> to send a second one.');

            return self::FAILURE;
        }

        $payload = $push->payloadFor($article);
        $audience = PushSubscription::query()->forBreaking()->count();

        $this->components->twoColumnDetail('Title', $payload['title']);
        $this->components->twoColumnDetail('Body', $payload['body']);
        $this->components->twoColumnDetail('Opens', $payload['url']);
        $this->components->twoColumnDetail('Audience', number_format($audience).' subscription(s)');

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — nothing was sent.');

            return self::SUCCESS;
        }

        if ($audience === 0) {
            $this->components->warn('Nobody is subscribed yet. Nothing to send.');

            return self::SUCCESS;
        }

        // Asked only when somebody is actually there to answer. A scripted or
        // cron run has already made the decision by invoking this at all, and
        // a confirmation prompt that defaults to "no" would turn that into a
        // command which quietly does nothing.
        if ($this->input->isInteractive()
            && ! $this->confirm("Send to {$audience} subscription(s)? This cannot be undone.", false)) {
            $this->components->info('Cancelled.');

            return self::SUCCESS;
        }

        $result = $push->send($payload);

        // Stamped even on a partial failure. The story went out; a second run
        // would send it twice to everybody it reached the first time, which is
        // worse than the handful it missed.
        $article->forceFill(['push_sent_at' => now()])->save();

        $this->components->twoColumnDetail('Delivered', (string) $result->sent);
        $this->components->twoColumnDetail('Pruned (browser gone)', (string) $result->pruned);
        $this->components->twoColumnDetail('Failed', (string) $result->failed);

        foreach ($result->reasons as $reason) {
            $this->components->warn($reason);
        }

        return self::SUCCESS;
    }
}
