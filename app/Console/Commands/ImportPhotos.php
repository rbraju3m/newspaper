<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use App\Enums\UserRole;
use App\Services\HomepageService;
use App\Services\ImageService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Imports a folder of real photographs into the media library.
 *
 * `MediaSeeder` draws section-coloured plates, which are enough to exercise the
 * ladder and measure CLS but are abstract compositions rather than pictures —
 * useless for judging a crop, a focal point or how a headline sits over an
 * image. This is the way to put actual photojournalism in front of the layout
 * without hand-uploading a hundred files through the admin.
 *
 * It is a command rather than a seeder on purpose: the source folder lives on
 * whoever's machine has the photographs, and a path like that baked into
 * `db:seed` would fail on every other box and in CI.
 *
 * Two things it does that a plain copy would not:
 *
 * Every source is transcoded to JPEG before it reaches `ImageService`. The
 * service keeps what it is given as the stored original, and that original is
 * the plain `src` behind every responsive image — hand it a 2 MB PNG and every
 * browser without WebP support downloads 2 MB.
 *
 * And it is idempotent, the same way `MediaSeeder` is: a photo is looked up by
 * `filename` first, so re-running relinks against what is already there rather
 * than storing a second copy, and a row whose ladder predates a change to
 * `ImageService::WIDTHS` is re-derived rather than left behind.
 */
class ImportPhotos extends Command
{
    protected $signature = 'photos:import
        {directory : Folder of photographs to import}
        {--credit= : Credit line stored on every imported photo}
        {--folder=photos : Media library folder to group the uploads under}
        {--assign : Link the imported photos across every article}
        {--quality=88 : JPEG quality of the stored original}
        {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Import a folder of photographs into the media library';

    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function handle(ImageService $images): int
    {
        if (! function_exists('imagewebp')) {
            $this->components->error('GD has no WebP support — the derivative ladder cannot be built.');

            return self::FAILURE;
        }

        $directory = rtrim((string) $this->argument('directory'), '/');

        if (! is_dir($directory) || ! is_readable($directory)) {
            $this->components->error("Not a readable directory: {$directory}");

            return self::FAILURE;
        }

        $files = $this->sources($directory);

        if ($files === []) {
            $this->components->warn('No images found in '.$directory);

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        $this->components->info(($dry ? 'Would import ' : 'Importing ').count($files).' photographs…');

        $uploader = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        $photos = [];
        $stored = 0;
        $reused = 0;
        $rebuilt = 0;
        $failed = [];

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $path) {
            $filename = pathinfo($path, PATHINFO_FILENAME).'.jpg';

            if ($existing = Media::query()->where('filename', $filename)->first()) {
                if (! $dry && ! $images->hasCurrentLadder($existing)) {
                    $existing = $images->regenerate($existing);
                    $rebuilt++;
                } else {
                    $reused++;
                }

                $photos[] = $existing;
                $bar->advance();

                continue;
            }

            if ($dry) {
                $stored++;
                $bar->advance();

                continue;
            }

            try {
                $photos[] = $this->store($images, $path, $filename, $uploader?->id);
                $stored++;
            } catch (\Throwable $e) {
                $failed[] = basename($path).': '.$e->getMessage();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($failed as $message) {
            $this->components->error($message);
        }

        $this->components->twoColumnDetail('Stored', (string) $stored);
        $this->components->twoColumnDetail('Already in the library', (string) $reused);

        if ($rebuilt) {
            $this->components->twoColumnDetail('Ladder rebuilt', (string) $rebuilt);
        }

        if ($this->option('assign')) {
            $this->assign($photos, count($files), $dry);
        }

        if (! $dry) {
            HomepageService::flush();
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> absolute paths, in natural order */
    private function sources(string $directory): array
    {
        $files = [];

        foreach (File::files($directory) as $file) {
            if (in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                $files[] = $file->getPathname();
            }
        }

        // Natural order, so `2.png` sorts before `10.png` and the deterministic
        // assignment below is stable across machines.
        natsort($files);

        return array_values($files);
    }

    /**
     * Transcodes to JPEG and hands the result to ImageService as an upload.
     *
     * The alpha channel is flattened onto white rather than dropped: a PNG with
     * transparency written straight to JPEG comes out with black where the
     * transparency was.
     */
    private function store(ImageService $images, string $path, string $filename, ?int $userId): Media
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));

        if ($source === false) {
            throw new \RuntimeException('not a readable image');
        }

        $flat = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imagedestroy($source);

        // tempnam() has no extension and does not need one: ImageService reads
        // the extension back off the sniffed mime type, not the name.
        $tmp = tempnam(sys_get_temp_dir(), 'photo-import-');

        imagejpeg($flat, $tmp, max(1, min(100, (int) $this->option('quality'))));
        imagedestroy($flat);

        try {
            return $images->store(
                new UploadedFile($tmp, $filename, 'image/jpeg', null, true),
                $userId,
                ['credit' => $this->option('credit') ?: null],
                (string) $this->option('folder'),
            );
        } finally {
            File::delete($tmp);
        }
    }

    /**
     * Spreads the library across every article, the way `MediaSeeder` does.
     *
     * Both columns are written: `image_id` feeds the srcset and the
     * denormalised `image` feeds the plain `src`, so leaving the second on the
     * old path would keep a stale file as the fallback of every responsive
     * image on the site.
     *
     * @param  list<Media>  $photos  empty on a dry run, which is why the pool
     *                                 size is passed separately
     */
    private function assign(array $photos, int $poolSize, bool $dry): void
    {
        $pool = $dry ? $poolSize : count($photos);

        if ($pool === 0) {
            $this->components->warn('Nothing to assign.');

            return;
        }

        $credit = $this->option('credit') ?: null;

        /** @var array<int, list<int>> $buckets media id => article ids */
        $buckets = [];

        Article::withTrashed()
            ->select(['id'])
            ->orderBy('id')
            ->chunk(200, function ($articles) use (&$buckets, $photos, $pool, $dry): void {
                foreach ($articles as $article) {
                    // Deterministic rather than random, so re-running does not
                    // reshuffle the whole front page. On a dry run the media
                    // rows do not exist yet, so bucket by slot instead.
                    $slot = $article->id % $pool;

                    $buckets[$dry ? $slot : $photos[$slot]->id][] = $article->id;
                }
            });

        $total = array_sum(array_map('count', $buckets));

        if ($dry) {
            $this->components->twoColumnDetail('Would link', $total.' articles across '.count($buckets).' photographs');

            return;
        }

        $byId = collect($photos)->keyBy('id');

        foreach ($buckets as $mediaId => $ids) {
            // A query-builder update, so no article model events fire. Nothing
            // here needs them, and firing them once per article would recount
            // every counter on the table.
            Article::withTrashed()->whereIn('id', $ids)->update(array_filter([
                'image_id' => $mediaId,
                'image' => $byId[$mediaId]->path,
                'image_credit' => $credit,
            ], fn ($value) => $value !== null));
        }

        $this->components->twoColumnDetail('Linked', $total.' articles across '.count($buckets).' photographs');
    }
}
