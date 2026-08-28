<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Draws the app icon set from the brand colour.
 *
 * These were one-off files before, which meant a colour change or a fix to the
 * maskable safe zone was a job nobody could repeat. They are drawn rather than
 * designed on purpose, and they carry **no lettering**: GD does no complex
 * shaping, so Bangla conjuncts and vowel signs come out unformed and out of
 * order — the same reason `EpaperSeeder` draws a nameplate-shaped block rather
 * than a nameplate. A wrong masthead in front of a Bangla newsroom is worse
 * than no masthead.
 *
 * Two canvases, not one, because `any` and `maskable` are different jobs:
 *
 * - **`any`** is shown as drawn. It can use the whole canvas, and a small
 *   margin is all it wants.
 * - **`maskable`** is cropped to whatever shape the platform likes — Android
 *   uses a circle, a squircle or a rounded square depending on the launcher.
 *   Only the centre 80% is guaranteed to survive, so everything that must be
 *   seen goes inside a *circle* of that diameter, which is tighter than the
 *   80% square people usually assume.
 *
 * The old icon-512 was declared `maskable` while its content ran from 19% to
 * 80% of the canvas: the corners of the white page were clipped on every
 * circular launcher, and nothing said so.
 */
class BrandIcons extends Command
{
    protected $signature = 'brand:icons {--force : Overwrite existing files}';

    protected $description = 'Draw the favicon and PWA icon set from the brand colour';

    /** Brand red, matching --color-brand in resources/css/app.css. */
    private const BRAND = [0xC8, 0x10, 0x2E];

    private const PAPER = [0xFF, 0xFF, 0xFF];

    public function handle(): int
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->error('GD is not available.');

            return self::FAILURE;
        }

        $dir = public_path('images');
        File::ensureDirectoryExists($dir);

        $targets = [
            ['icon-512.png', 512, false],
            ['icon-512-maskable.png', 512, true],
            ['icon-192.png', 192, false],
            ['icon-180.png', 180, false],
            ['favicon-32.png', 32, false],
        ];

        foreach ($targets as [$name, $size, $maskable]) {
            $path = $dir.'/'.$name;

            if (File::exists($path) && ! $this->option('force')) {
                $this->components->twoColumnDetail($name, '<fg=yellow>exists, skipped</>');

                continue;
            }

            imagepng($this->draw($size, $maskable), $path, 9);
            $this->components->twoColumnDetail($name, number_format(filesize($path)).' B');
        }

        $this->writeFavicon(public_path('favicon.ico'));

        $this->components->info('Icons drawn into public/images and public/favicon.ico.');

        return self::SUCCESS;
    }

    /**
     * A newspaper page on a brand field: a masthead rule, then column rules of
     * decreasing length. Wordless, and readable at 32px, which is the only
     * size most people ever see.
     */
    private function draw(int $size, bool $maskable): \GdImage
    {
        $im = imagecreatetruecolor($size, $size);
        imagealphablending($im, true);
        imagesavealpha($im, true);

        $brand = imagecolorallocate($im, ...self::BRAND);
        $paper = imagecolorallocate($im, ...self::PAPER);

        imagefilledrectangle($im, 0, 0, $size - 1, $size - 1, $brand);

        // The page. A maskable icon may only rely on a circle of 80% diameter,
        // so the page is inscribed in that circle rather than in an 80% square
        // — a square that touches the circle has its corners outside it.
        // 0.76 rather than 0.80: a square inscribed in the safe circle has its
        // corners exactly *on* it, and rounding to whole pixels then puts them
        // a pixel outside. The margin is the difference between "correct on
        // paper" and "correct on a launcher".
        $fraction = $maskable ? 0.76 / M_SQRT2 : 0.62;
        $side = (int) round($size * $fraction);
        $x = (int) round(($size - $side) / 2);
        $y = $x;

        imagefilledrectangle($im, $x, $y, $x + $side - 1, $y + $side - 1, $paper);

        // Rules: a heavy masthead bar, a gap, then four column lines whose
        // lengths fall away. Proportional to the page so every size matches.
        $pad = (int) round($side * 0.12);
        $left = $x + $pad;
        $width = $side - 2 * $pad;
        $bar = max(2, (int) round($side * 0.085));
        $gap = max(2, (int) round($side * 0.062));
        $top = $y + $pad;

        imagefilledrectangle($im, $left, $top, $left + $width - 1, $top + $bar - 1, $brand);
        $top += $bar + $gap + max(1, (int) round($side * 0.03));

        foreach ([0.86, 0.62, 0.92, 0.48] as $run) {
            $line = max(2, (int) round($side * 0.055));
            imagefilledrectangle(
                $im, $left, $top,
                $left + (int) round($width * $run) - 1, $top + $line - 1, $brand,
            );
            $top += $line + $gap;
        }

        return $im;
    }

    /**
     * A real multi-size `.ico`, because `public/favicon.ico` was a zero-byte
     * file — served as an empty 200, which is not the same as absent. A
     * browser that falls back to `/favicon.ico` (they still do, whatever the
     * `<link rel="icon">` says) got nothing and cached it.
     *
     * ICO has carried embedded PNGs since Vista, so each entry is simply the
     * PNG bytes with a 16-byte directory record in front.
     */
    private function writeFavicon(string $path): void
    {
        $sizes = [16, 32, 48];
        $pngs = [];

        foreach ($sizes as $size) {
            ob_start();
            imagepng($this->draw($size, false), null, 9);
            $pngs[] = ob_get_clean();
        }

        // ICONDIR: reserved, type 1 (icon), image count.
        $ico = pack('vvv', 0, 1, count($pngs));
        $offset = 6 + 16 * count($pngs);

        foreach ($pngs as $i => $png) {
            // width, height (0 would mean 256), palette, reserved,
            // planes, bpp, byte length, offset.
            $ico .= pack('CCCCvvVV', $sizes[$i], $sizes[$i], 0, 0, 1, 32, strlen($png), $offset);
            $offset += strlen($png);
        }

        File::put($path, $ico.implode('', $pngs));

        $this->components->twoColumnDetail('favicon.ico', number_format(filesize($path)).' B');
    }
}
