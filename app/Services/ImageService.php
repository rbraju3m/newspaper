<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
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

    private function isConvertible(?string $mime): bool
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
