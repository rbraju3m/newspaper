<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\LiveEntry;
use App\Models\Page;
use App\Support\Bangla;
use App\Support\Html;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Brings stored bodies up to the allow-list in `App\Support\Html`.
 *
 * The three models that hold editor-written HTML sanitise it in their own
 * `saving()` hooks, so the invariant holds from now on — but only for rows that
 * are saved again. Everything written before sanitising existed, and everything
 * a later widening of the allow-list would newly reject, needs this.
 *
 * Safe to run repeatedly: sanitising is idempotent, so a second run reports
 * every row as already clean and writes nothing.
 *
 * Writes with the query builder rather than save(). Model events would only
 * re-run the same sanitiser, and a save() here would move `updated_at` on
 * hundreds of rows that nobody edited.
 */
class SanitizeContentBodies extends Command
{
    /**
     * Every table with a `body` column that something renders unescaped, and
     * the column that identifies a row in the report.
     *
     * If a fourth one is ever added, it belongs here on the same day it starts
     * being printed with `{!! !!}`.
     */
    private const TARGETS = [
        'articles' => [Article::class, 'Article bodies', 'title'],
        'pages' => [Page::class, 'Static pages', 'title'],
        'live' => [LiveEntry::class, 'Live-blog entries', 'headline'],
    ];

    protected $signature = 'content:sanitize
        {--only= : One of articles, pages, live — default is all three}
        {--dry-run : Report what would change and write nothing}
        {--chunk=100 : Rows per query}
        {--show=12 : How many affected rows to list per target}';

    protected $description = 'Re-sanitise stored article, page and live-blog bodies against the current HTML allow-list';

    public function handle(): int
    {
        $only = $this->option('only');

        if ($only !== null && ! array_key_exists($only, self::TARGETS)) {
            $this->components->error("Unknown target '{$only}'. Expected one of: ".implode(', ', array_keys(self::TARGETS)).'.');

            return self::FAILURE;
        }

        $targets = $only !== null
            ? [$only => self::TARGETS[$only]]
            : self::TARGETS;

        $dirty = 0;

        foreach ($targets as [$model, $label, $nameColumn]) {
            $dirty += $this->sweep($model, $label, $nameColumn);
        }

        if ($this->option('dry-run') && $dirty > 0) {
            $this->newLine();
            $this->components->warn('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $model
     * @return int rows that would be, or were, rewritten
     */
    private function sweep(string $model, string $label, string $nameColumn): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $show = max(0, (int) $this->option('show'));

        $query = $this->query($model);
        $total = (clone $query)->count();

        $this->newLine();
        $this->components->twoColumnDetail("<fg=cyan>{$label}</>", $total === 0 ? 'nothing to check' : $total.' rows');

        if ($total === 0) {
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $changed = $clean = 0;
        $stripped = ['dropped' => [], 'unwrapped' => [], 'attributes' => []];
        $affected = [];

        $query->select(['id', $nameColumn, 'body'])->orderBy('id')->chunkById($chunk, function ($rows) use (
            $model, $nameColumn, $dry, $bar, $show, &$changed, &$clean, &$stripped, &$affected
        ): void {
            foreach ($rows as $row) {
                $bar->advance();

                $body = (string) $row->body;
                $sanitised = Html::sanitize($body, $report);

                if ($sanitised === $body) {
                    $clean++;

                    continue;
                }

                $changed++;

                foreach ($report as $kind => $counts) {
                    foreach ($counts as $key => $count) {
                        $stripped[$kind][$key] = ($stripped[$kind][$key] ?? 0) + $count;
                    }
                }

                // A row whose markup only needed closing tags is not worth
                // listing; one that lost something is.
                $removals = array_sum(array_map('array_sum', $report));

                if ($removals > 0 && count($affected) < $show) {
                    $affected[] = [$row->id, $row->{$nameColumn}, $removals];
                }

                if (! $dry) {
                    DB::table($row->getTable())->where('id', $row->id)->update(
                        $this->columns($model, $sanitised)
                    );
                }
            }
        }, 'id');

        $bar->finish();
        $this->newLine();

        $this->components->twoColumnDetail('  Already clean', (string) $clean);
        $this->components->twoColumnDetail('  '.($dry ? 'Would rewrite' : 'Rewritten'), (string) $changed);

        $this->report($stripped, $affected);

        return $changed;
    }

    /**
     * Soft-deleted rows are restorable, so they are in scope. Only rows with a
     * body are: sanitising null is null.
     */
    private function query(string $model): Builder
    {
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
            ? $model::withTrashed()
            : $model::query();

        return $query->whereNotNull('body')->where('body', '!=', '');
    }

    /**
     * An article's reading time is derived from its body, so a body that lost
     * markup has a reading time that no longer matches it. Nothing else here
     * denormalises anything.
     */
    private function columns(string $model, string $sanitised): array
    {
        return $model === Article::class
            ? ['body' => $sanitised, 'reading_time' => Bangla::readingTime($sanitised)]
            : ['body' => $sanitised];
    }

    private function report(array $stripped, array $affected): void
    {
        $labels = [
            'dropped' => 'Elements dropped',
            'unwrapped' => 'Elements unwrapped',
            'attributes' => 'Attributes removed',
        ];

        foreach ($labels as $kind => $label) {
            if ($stripped[$kind] === []) {
                continue;
            }

            arsort($stripped[$kind]);
            $this->components->twoColumnDetail("  <fg=yellow>{$label}</>", '');

            foreach ($stripped[$kind] as $key => $count) {
                $this->components->twoColumnDetail('    '.$key, (string) $count);
            }
        }

        if ($affected === []) {
            return;
        }

        $this->components->twoColumnDetail('  <fg=yellow>Rows that lost markup</>', '');

        foreach ($affected as [$id, $name, $removals]) {
            $this->components->twoColumnDetail(
                '    #'.$id.' '.mb_strimwidth((string) ($name ?: '—'), 0, 60, '…'),
                $removals.' removed',
            );
        }
    }
}
