<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every denormalised counter from the rows it summarises.
 *
 * The counters are maintained by model events — `Article::booted()` for the
 * article counts, `Comment::booted()` for the comment ones — and that is where
 * they should stay correct. This is the reconcile, for the cases events cannot
 * see:
 *
 *  - a bulk `whereIn()->update()`, which fires no events at all
 *  - an import, a manual fix in tinker, a restored backup
 *  - a bug in the hooks themselves, which is the case that matters most
 *
 * It reports how many rows were wrong *before* fixing them, which is the
 * output worth reading: a nightly run that always reports zero says the events
 * are doing their job, and the first non-zero night says they are not.
 *
 * The definitions here are the source of truth. `ContentSeeder` calls this
 * command rather than keeping its own copy — two definitions of a correct
 * count is how the thing that fixes drift comes to disagree about it.
 */
class RecomputeCounters extends Command
{
    /**
     * label => [table, column, correlated subquery giving the true value]
     *
     * "Published" means status published *and* not trashed, matching what
     * `Article::scopePublished()` and the public site consider real.
     */
    private const COUNTERS = [
        'categories.articles_count' => ['categories', 'articles_count',
            'SELECT COUNT(*) FROM articles a WHERE a.category_id = t.id
             AND a.status = "published" AND a.deleted_at IS NULL', ],

        'users.articles_count' => ['users', 'articles_count',
            'SELECT COUNT(*) FROM articles a WHERE a.author_id = t.id
             AND a.status = "published" AND a.deleted_at IS NULL', ],

        'tags.articles_count' => ['tags', 'articles_count',
            'SELECT COUNT(*) FROM article_tag at WHERE at.tag_id = t.id'],

        'topics.articles_count' => ['topics', 'articles_count',
            'SELECT COUNT(*) FROM article_topic at WHERE at.topic_id = t.id'],

        'articles.comments_count' => ['articles', 'comments_count',
            'SELECT COUNT(*) FROM comments c WHERE c.article_id = t.id
             AND c.status = "approved" AND c.deleted_at IS NULL', ],

        // Unconditional: a gallery image has no status and no soft delete, so
        // every row counts.
        'galleries.images_count' => ['galleries', 'images_count',
            'SELECT COUNT(*) FROM gallery_images gi WHERE gi.gallery_id = t.id'],
    ];

    protected $signature = 'counters:recompute
        {--dry-run : Report the drift and correct nothing}
        {--quiet-when-clean : Print nothing if every counter is already right}';

    protected $description = 'Recompute denormalised counters and report how far they had drifted';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $drifted = 0;
        $rows = [];

        foreach (self::COUNTERS as $label => [$table, $column, $subquery]) {
            $wrong = (int) DB::table("{$table} as t")
                ->whereRaw("t.`{$column}` <> ({$subquery})")
                ->count();

            $drifted += $wrong;
            $rows[$label] = $wrong;

            if ($wrong > 0 && ! $dry) {
                DB::statement("UPDATE `{$table}` t SET t.`{$column}` = ({$subquery})");
            }
        }

        if ($drifted === 0 && $this->option('quiet-when-clean')) {
            return self::SUCCESS;
        }

        foreach ($rows as $label => $wrong) {
            $this->components->twoColumnDetail(
                $wrong === 0 ? $label : "<fg=yellow>{$label}</>",
                $wrong === 0 ? 'correct' : $wrong.' row(s) '.($dry ? 'wrong' : 'corrected'),
            );
        }

        if ($drifted === 0) {
            $this->components->info('Every counter was already correct.');
        } elseif ($dry) {
            $this->components->warn($drifted.' row(s) have drifted. Re-run without --dry-run to correct them.');
        } else {
            $this->components->info('Corrected '.$drifted.' drifted row(s).');
        }

        return self::SUCCESS;
    }
}
