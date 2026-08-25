<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an upload and derives the sizes the site actually renders.
 *
 * Every reference paper serves one oversized JPEG to every breakpoint. We
 * generate a small ladder of WebP derivatives instead, so a phone downloads
 * ~40KB where those sites push 400KB, and record the widths so templates can
 * emit a real srcset.
 */
class ImageService
{
    /** Rendered widths, matched to the card variants in the design system. */
    private const WIDTHS = [
        'w320' => 320,    // mobile card
        'w640' => 640,    // tablet card / mobile hero
        'w768' => 768,    // full-bleed hero on a mid-range phone
        'w960' => 960,    // desktop feature
        'w1600' => 1600,  // article hero
    ];

    private const THUMB = 200;

    private const QUALITY = 82;

    public function store(UploadedFile $file, ?int $userId = null, array $meta = []): Media
    {
        $disk = 'public';
        $dir = 'uploads/'.now()->format('Y/m');
        $name = Str::random(24);

        $original = $file->storeAs($dir, $name.'.'.$file->extension(), $disk);

        [$width, $height] = $this->dimensions(Storage::disk($disk)->path($original));

        $conversions = $this->isConvertible($file->getMimeType())
            ? $this->deriveAll(Storage::disk($disk)->path($original), $dir, $name, $width, $disk)
            : [];

        return Media::create([
            'user_id' => $userId,
            'disk' => $disk,
            'path' => $original,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'conversions' => $conversions,
            'alt' => $meta['alt'] ?? null,
            'caption' => $meta['caption'] ?? null,
            'credit' => $meta['credit'] ?? null,
        ]);
    }

    /**
     * Rebuilds the derivative ladder for an upload that is already stored.
     *
     * Adding a rung to WIDTHS is inert on everything already in the media
     * table: srcset is built from the `conversions` the row recorded, not from
     * this constant. Derivative filenames are the original's name plus the rung
     * key, so a rebuild overwrites the rungs it still produces; a rung dropped
     * from WIDTHS just stops being referenced and its file is left behind.
     */
    public function regenerate(Media $media): Media
    {
        $disk = Storage::disk($media->disk);

        if (! $this->isConvertible($media->mime) || ! $disk->exists($media->path)) {
            return $media;
        }

        $media->update([
            'conversions' => $this->deriveAll(
                $disk->path($media->path),
                dirname($media->path),
                pathinfo($media->path, PATHINFO_FILENAME),
                $media->width,
                $media->disk,
            ),
        ]);

        return $media;
    }

    /**
     * Whether a stored row already carries everything this service would
     * produce for it today.
     *
     * The one place that decides "is this ladder current" — the seeder's
     * self-heal and the backfill command both ask here, so a WIDTHS change
     * cannot leave the two disagreeing about what needs rebuilding.
     *
     * A row this service would never convert (an SVG, a PDF) counts as current:
     * there is no ladder to be behind on.
     */
    public function hasCurrentLadder(Media $media): bool
    {
        if (! $this->isConvertible($media->mime)) {
            return true;
        }

        $have = array_keys($media->conversions ?? []);

        // `thumb` is not a rendering width, so rungsFor() omits it, but every
        // convertible source earns one and the admin grid falls back to the
        // full-size original without it.
        return ! array_diff([...$this->rungsFor($media->width), 'thumb'], $have);
    }

    /**
     * The rung keys this service would produce for a source of the given width.
     *
     * Lets a caller tell a media row whose ladder predates a WIDTHS change from
     * one that is simply too small to have earned the upper rungs.
     *
     * @return list<string>
     */
    public function rungsFor(?int $sourceWidth): array
    {
        $first = array_key_first(self::WIDTHS);

        return array_keys(array_filter(
            self::WIDTHS,
            fn (int $target, string $key): bool => ! $sourceWidth || $target <= $sourceWidth || $key === $first,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** Whether this service can derive a ladder from the given mime type. */
    public function isConvertible(?string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            && function_exists('imagecreatefromstring');
    }

    /** @return array<string, string> conversion key => stored path */
    private function deriveAll(string $path, string $dir, string $name, ?int $srcWidth, string $disk): array
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));

        if (! $source) {
            return [];
        }

        $out = [];

        foreach (self::WIDTHS as $key => $target) {
            // Never upscale — a 400px source rendered at 1600 is just blur.
            if ($srcWidth && $target > $srcWidth && $key !== array_key_first(self::WIDTHS)) {
                continue;
            }

            if ($p = $this->resizeTo($source, $dir, $name, $key, $target, $disk)) {
                $out[$key] = $p;
            }
        }

        if ($p = $this->resizeTo($source, $dir, $name, 'thumb', self::THUMB, $disk)) {
            $out['thumb'] = $p;
        }

        imagedestroy($source);

        return $out;
    }

    private function resizeTo(\GdImage $source, string $dir, string $name, string $key, int $target, string $disk): ?string
    {
        $w = imagesx($source);
        $h = imagesy($source);

        $newW = min($target, $w);
        $newH = (int) round($h * ($newW / $w));

        $canvas = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG/WebP sources.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $w, $h);

        $relative = $dir.'/'.$name.'-'.$key.'.webp';
        $absolute = Storage::disk($disk)->path($relative);

        Storage::disk($disk)->makeDirectory($dir);

        $ok = imagewebp($canvas, $absolute, self::QUALITY);
        imagedestroy($canvas);

        return $ok ? $relative : null;
    }

    /** @return array{0:?int,1:?int} */
    private function dimensions(string $path): array
    {
        $info = @getimagesize($path);

        return [$info[0] ?? null, $info[1] ?? null];
    }

    /**
     * Everything that can hold a reference to a stored file.
     *
     * Two columns point at `media` by id; the rest keep a bare path, because
     * most of these modules predate the media library. A file is only safe to
     * delete once none of them mention it.
     *
     * @var array<string, list<string>>
     */
    private const PATH_REFERENCES = [
        'articles' => ['image'],
        'topics' => ['image'],
        'live_entries' => ['image'],
        'galleries' => ['cover'],
        'gallery_images' => ['path'],
        'epapers' => ['pdf', 'cover'],
        'epaper_pages' => ['image', 'pdf'],
        'ads' => ['asset'],
        'users' => ['avatar'],
    ];

    /**
     * Drops a file that nothing points at any more, media row and all.
     *
     * Call it *after* the owning row has been updated, so the caller's own old
     * value is already gone and does not count as a reference to itself.
     *
     * Reference counted rather than unconditional: `articles.image_id` and
     * `gallery_images.media_id` are `nullOnDelete`, so deleting a media row two
     * articles share would silently blank the other one's lead image. The same
     * file can also be reached by path from nine more columns.
     *
     * Returns whether anything was removed.
     */
    public function release(?string $path): bool
    {
        // An external URL is not ours, and neither is an empty column.
        if (! $path || str_starts_with($path, 'http')) {
            return false;
        }

        $media = Media::query()->where('path', $path)->first();

        if ($this->isReferenced($path, $media?->id)) {
            return false;
        }

        if ($media) {
            $this->delete($media);

            return true;
        }

        // Untracked: a legacy upload that never went through this service. The
        // file is still ours to remove, there is just no row or ladder with it.
        return Storage::disk('public')->delete($path);
    }

    /** Whether any row anywhere still points at this file. */
    private function isReferenced(string $path, ?int $mediaId): bool
    {
        // Deliberately the query builder, not Eloquent: soft-deleted articles
        // still reference their image, and restoring one whose media had been
        // reaped would leave it with a dead path.
        if ($mediaId && (
            DB::table('articles')->where('image_id', $mediaId)->exists()
            || DB::table('gallery_images')->where('media_id', $mediaId)->exists()
        )) {
            return true;
        }

        foreach (self::PATH_REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, $path)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Removes the original and every derivative. */
    public function delete(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        $disk->delete($media->path);

        foreach ($media->conversions ?? [] as $path) {
            if (is_string($path)) {
                $disk->delete($path);
            }
        }

        $media->delete();
    }
}
