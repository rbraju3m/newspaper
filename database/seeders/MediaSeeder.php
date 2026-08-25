<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\Article;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use App\Services\AdService;
use App\Services\HomepageService;
use App\Services\ImageService;
use Database\Seeders\Support\SeedImagery;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Gives the demo database something to actually render.
 *
 * Every seeded article carried `image = 'seed/N.jpg'` against an empty
 * `storage/app/public` and a `media` table with no rows, so all 374 of them
 * pointed at a 404 and `Article::image_srcset` returned null for every one —
 * the Phase 6 ladder was wired up and completely inert. This draws a small
 * library of section-coloured plates, pushes each through the real
 * ImageService so the WebP derivatives are produced the same way an editor's
 * upload would produce them, and links the results.
 *
 * Runs last, after ContentSeeder: it rewrites columns those seeders wrote.
 *
 * Idempotent. Media are looked up by `filename`, so re-running relinks against
 * the existing library instead of drawing and storing a second copy. Deleting
 * the `seed-*` rows (and their files) is enough to force a redraw.
 */
class MediaSeeder extends Seeder
{
    /** Plates per section. Enough that a section page is not three of one image. */
    private const VARIANTS = 3;

    /** Oversized on purpose: the ladder tops out at 1600 and must not upscale. */
    private const SOURCE_W = 2400;

    private const SOURCE_H = 1350;

    private const QUALITY = 88;

    /**
     * The factory's default is 'ছবি: সংগৃহীত' — "photo: collected" — which is a
     * provenance claim these drawn plates cannot support, even in demo data.
     */
    private const CREDIT = 'ছবি: প্রতীকী (ডেমো)';

    public function run(): void
    {
        if (! function_exists('imagewebp')) {
            $this->command->warn('GD has no WebP support — skipping imagery.');

            return;
        }

        $service = app(ImageService::class);
        $uploader = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        $library = $this->library($service, $uploader?->id);
        $this->assignToArticles($library);
        $this->assignToAds($service, $uploader?->id);

        SeedImagery::releaseNoise();

        // Both caches hold the rows just rewritten. Without this the homepage
        // serves the old payload — with the broken paths — until it expires.
        HomepageService::flush();
        AdService::flush();
    }

    /**
     * One plate set per top-level section, drawn from that section's colour.
     *
     * @return array<string, list<Media>> section slug => plates
     */
    private function library(ImageService $service, ?int $userId): array
    {
        $sections = Category::query()->whereNull('parent_id')->orderBy('id')->get();

        $this->command->info("Drawing imagery for {$sections->count()} sections…");

        $library = [];

        foreach ($sections as $section) {
            for ($variant = 1; $variant <= self::VARIANTS; $variant++) {
                $filename = "seed-{$section->slug}-{$variant}.jpg";

                $library[$section->slug][] = $this->refreshed($service, Media::query()->where('filename', $filename)->first())
                    ?? $this->store(
                        $service,
                        (new SeedImagery(crc32($section->slug.$variant)))
                            ->photo($section->color ?: '#C8102E', self::SOURCE_W, self::SOURCE_H),
                        $filename,
                        $userId,
                        [
                            'alt' => $section->name.' বিভাগের প্রতীকী ছবি',
                            'credit' => self::CREDIT,
                        ],
                    );
            }

            $this->command->getOutput()->write('.');
        }

        $this->command->getOutput()->writeln('');

        return $library;
    }

    /**
     * Re-derives a plate whose ladder predates a change to `ImageService`.
     *
     * Without this the seeder's own idempotency works against it: a plate found
     * by filename is returned untouched, so adding a rung to WIDTHS would leave
     * every existing plate on the old ladder and the new rung would never reach
     * a srcset. Only rebuilds when a rung is actually missing, so the warm path
     * stays sub-second.
     */
    private function refreshed(ImageService $service, ?Media $media): ?Media
    {
        if (! $media) {
            return null;
        }

        return $service->hasCurrentLadder($media) ? $media : $service->regenerate($media);
    }

    /**
     * Links every article to a plate from its own section.
     *
     * Both columns are written. `image_id` is what feeds the srcset; the
     * denormalised `image` is what feeds the plain `src`, and leaving it on the
     * old seed path would keep a 404 as the fallback of every responsive image
     * on the site.
     *
     * @param  array<string, list<Media>>  $library
     */
    private function assignToArticles(array $library): void
    {
        // `path` is the materialised "khela/cricket"; its first segment names
        // the section whose palette the plate was drawn from. Resolving it from
        // a pluck avoids touching $category->parent, which strict mode forbids
        // outside an eager load.
        $sectionOf = Category::query()->pluck('path', 'id')
            ->map(fn (string $path): string => explode('/', $path)[0]);

        $fallback = array_merge(...array_values($library));
        $plates = collect($fallback)->keyBy('id');

        /** @var array<int, list<int>> $buckets media id => article ids */
        $buckets = [];

        Article::withTrashed()
            ->select(['id', 'category_id'])
            ->orderBy('id')
            ->chunk(200, function ($articles) use (&$buckets, $sectionOf, $library, $fallback): void {
                foreach ($articles as $article) {
                    $pool = $library[$sectionOf[$article->category_id] ?? ''] ?? $fallback;

                    // Deterministic rather than random, so re-running the
                    // seeder does not reshuffle the whole front page.
                    $buckets[$pool[$article->id % count($pool)]->id][] = $article->id;
                }
            });

        foreach ($buckets as $mediaId => $ids) {
            // A query-builder update, so none of the article model events fire.
            // Nothing here needs them, and firing them 374 times would recount
            // every counter on the table.
            Article::withTrashed()->whereIn('id', $ids)->update([
                'image_id' => $mediaId,
                'image' => $plates[$mediaId]->path,
                'image_credit' => self::CREDIT,
            ]);
        }

        $this->command->info('Linked '.array_sum(array_map('count', $buckets)).' articles to imagery.');
    }

    /**
     * Fills the house ad slots.
     *
     * Every seeded ad is `type=image` with a null asset, so the admin ad list
     * previewed six broken thumbnails and there was no creative to check the
     * reserved-box geometry against.
     *
     * This does not change the public site on its own: all six seeded ads ship
     * with `is_active = 0`, so `Ad::live()` returns none and every slot renders
     * its "বিজ্ঞাপন" placeholder. Activating them is an editor's decision, not
     * a seeder's.
     */
    private function assignToAds(ImageService $service, ?int $userId): void
    {
        $slots = config('site.ad_slots', []);

        // Every image ad, not only the ones with a null asset: a filled slot
        // still needs its creative re-derived when the ladder changes, and
        // filtering those out made the refresh below unreachable.
        $ads = Ad::query()->where('type', 'image')->orderBy('id')->get();

        // Cycled so the six creatives are visibly different from one another.
        $palette = Category::query()->whereNull('parent_id')->orderBy('id')->pluck('color')
            ->filter()->values();

        $filled = 0;

        foreach ($ads as $i => $ad) {
            $filename = "seed-ad-{$ad->position}.jpg";
            $media = $this->refreshed($service, Media::query()->where('filename', $filename)->first());

            if (! $media) {
                // An editor's own creative is not ours to replace, and drawing
                // a seed one to sit unused would just litter the disk.
                if ($ad->asset !== null) {
                    continue;
                }

                $dim = $slots[$ad->position] ?? ['w' => 300, 'h' => 250];

                $media = $this->store(
                    $service,
                    (new SeedImagery(crc32($ad->position)))->creative(
                        $palette->get($i % max(1, $palette->count())) ?: '#C8102E',
                        $dim['w'],
                        $dim['h'],
                        $dim['w'].'x'.$dim['h'],
                    ),
                    $filename,
                    $userId,
                    ['alt' => $ad->title, 'credit' => self::CREDIT],
                );
            }

            if ($ad->asset === null || $ad->asset === $media->path) {
                $ad->update(['asset' => $media->path]);
                $filled++;
            }
        }

        if ($filled) {
            $this->command->info("Filled {$filled} ad slots.");
        }
    }

    /** Hands a drawn plate to ImageService as though it had been uploaded. */
    private function store(ImageService $service, \GdImage $image, string $filename, ?int $userId, array $meta): Media
    {
        // tempnam() has no extension and does not need one: ImageService reads
        // the extension back off the sniffed mime type, not the name.
        $tmp = tempnam(sys_get_temp_dir(), 'seed-imagery-');

        imagejpeg($image, $tmp, self::QUALITY);
        imagedestroy($image);

        try {
            return $service->store(
                new UploadedFile($tmp, $filename, 'image/jpeg', null, true),
                $userId,
                $meta,
            );
        } finally {
            File::delete($tmp);
        }
    }
}
