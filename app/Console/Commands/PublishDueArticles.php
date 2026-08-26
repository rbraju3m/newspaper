<?php

namespace App\Console\Commands;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Services\HomepageService;
use Illuminate\Console\Command;

/**
 * Publishes articles whose scheduled time has arrived.
 *
 * Until this existed, `scheduled` was a label rather than a mechanism: an
 * article with a future `published_at` sat there until somebody opened the
 * admin and changed its status by hand. The dashboard counted the overdue ones
 * (`scheduled_due`) so the newsroom would notice, which is a workaround for not
 * having this, not a feature.
 *
 * Deliberately narrow. It does exactly what the admin's status flip does —
 * moves the status to `published` and clears the homepage cache — and nothing
 * else. Anything it did beyond that would be a behaviour the newsroom gets from
 * the cron and not from the button, which is how the two paths drift apart.
 *
 * `published_at` is left alone. It is the time the editor chose, and it is
 * already in the past by the time this runs; overwriting it with `now()` would
 * replace "6 PM, as planned" with "6:00:37, when cron got round to it".
 */
class PublishDueArticles extends Command
{
    protected $signature = 'articles:publish-due
        {--dry-run : List what would be published and change nothing}';

    protected $description = 'Publish scheduled articles whose time has come';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // `published_at <= now()` excludes a null date on its own, so a
        // scheduled article that somehow lost its date is skipped rather than
        // published immediately.
        $due = Article::query()
            ->where('status', ArticleStatus::Scheduled)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;   // the overwhelmingly common case; stay quiet
        }

        foreach ($due as $article) {
            $this->components->twoColumnDetail(
                ($dry ? '<fg=yellow>would publish</> ' : '<fg=green>published</> ')
                    .mb_strimwidth($article->title, 0, 60, '…'),
                // A plain stamp, not diffForHumans(): Carbon localises the
                // words and not the digits, which reads as neither language in
                // an otherwise English ops line.
                'due '.$article->published_at->format('Y-m-d H:i'),
            );

            if (! $dry) {
                // Row by row rather than one whereIn()->update(): a bulk update
                // skips model events, and Article::saving() is where the slug
                // and reading time are kept honest.
                $article->status = ArticleStatus::Published;
                $article->save();
            }
        }

        if (! $dry) {
            // The front page is assembled from cached blocks, so a story
            // published here is invisible until this runs.
            HomepageService::flush();
        }

        $this->components->info(
            ($dry ? 'Would publish ' : 'Published ').$due->count().' article(s).'
        );

        return self::SUCCESS;
    }
}
