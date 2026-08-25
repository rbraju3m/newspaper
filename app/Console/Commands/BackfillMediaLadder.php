<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\ImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-derives WebP ladders for uploads that predate a change to
 * `ImageService::WIDTHS`.
 *
 * Adding a rung is inert on everything already stored: srcset is built from the
 * `conversions` a media row recorded, not from the constant. The seeder heals
 * the demo library on its own, but real editor uploads had nothing to bring
 * them forward — this is that piece.
 *
 * Safe to run repeatedly. Rows already carrying the current ladder are skipped
 * without touching the disk, so the cost of a no-op run is one query per chunk.
 */
class BackfillMediaLadder extends Command
{
    protected $signature = 'media:backfill
        {--force : Re-derive every convertible row, not only those missing a rung}
        {--dry-run : Report what would change and write nothing}
        {--chunk=100 : Rows per query}';

    protected $description = 'Rebuild WebP derivatives for media whose ladder is behind ImageService';

    public function handle(ImageService $images): int
    {
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $total = Media::query()->count();

        if ($total === 0) {
            $this->components->info('No media to check.');

            return self::SUCCESS;
        }

        $this->components->info(($dry ? 'Checking' : 'Backfilling').' '.$total.' media rows…');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $rebuilt = $current = $skipped = 0;
        $orphans = [];

        Media::query()->orderBy('id')->chunkById($chunk, function ($rows) use (
            $images, $force, $dry, $bar, &$rebuilt, &$current, &$skipped, &$orphans
        ): void {
            foreach ($rows as $media) {
                $bar->advance();

                // An SVG or a PDF has no ladder to be behind on. Counting those
                // as failures would make every run look half broken.
                if (! $images->isConvertible($media->mime)) {
                    $skipped++;

                    continue;
                }

                if (! Storage::disk($media->disk)->exists($media->path)) {
                    // The row outlived its file. Regenerating is impossible, and
                    // folding it into "skipped" would hide a broken record
                    // behind a clean summary.
                    $orphans[] = $media->id.'  '.$media->path;

                    continue;
                }

                if ($images->hasCurrentLadder($media) && ! $force) {
                    $current++;

                    continue;
                }

                if (! $dry) {
                    $images->regenerate($media);
                }

                $rebuilt++;
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('<fg=green>rebuilt</>', (string) $rebuilt);
        $this->components->twoColumnDetail('already current', (string) $current);

        if ($skipped) {
            $this->components->twoColumnDetail('skipped (not convertible)', (string) $skipped);
        }

        if ($orphans) {
            $this->newLine();
            $this->components->warn(count($orphans).' row(s) reference a file that is gone:');

            foreach (array_slice($orphans, 0, 20) as $line) {
                $this->line('   '.$line);
            }

            if (count($orphans) > 20) {
                $this->line('   … and '.(count($orphans) - 20).' more');
            }
        }

        if ($dry) {
            $this->newLine();
            $this->components->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }
}
