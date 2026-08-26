<?php

namespace Database\Seeders\Support;

/**
 * Draws the placeholder photography the demo database renders.
 *
 * Two things drove the shape of this. First, the images have to be *section
 * coloured*: a reader scrolling the seeded homepage should be able to tell
 * খেলা from অর্থনীতি at a glance, the way real section photography does.
 * Second — and this is the part a flat gradient gets wrong — they have to
 * carry realistic entropy. Phase 6 exists to measure LCP and the WebP ladder,
 * and a smooth gradient compresses to a handful of kilobytes at any width,
 * which would make every measurement flatteringly meaningless. So the
 * composition deliberately layers mid-frequency strokes and a grain pass that
 * survive the downscale to 640px.
 *
 * No Bangla text is ever drawn. GD's FreeType binding has no HarfBuzz behind
 * it, so it cannot shape conjuncts or reorder vowel signs — ক্রিকেট would come
 * out as mojibake baked into a JPEG. Latin labels only, and only on the ad
 * creative where identifying the slot is worth it.
 */
class SeedImagery
{
    /** Shared across every image in a run: generating it costs ~1s. */
    private static ?\GdImage $noise = null;

    private static int $noiseW = 0;

    private static int $noiseH = 0;

    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    private int $state;

    public function __construct(int $seed)
    {
        // Never mt_srand(): that would reseed the global generator Faker draws
        // from, making every other seeder's output depend on whether imagery
        // ran. This xorshift keeps determinism local to the instance.
        $this->state = ($seed * 2654435761) & 0xFFFFFFFF ?: 1;
    }

    // ── Compositions ─────────────────────────────────────────────────────

    /**
     * An editorial photo: soft depth-of-field wash, mid-frequency detail,
     * grain, vignette.
     */
    public function photo(string $hex, int $w = 2400, int $h = 1350): \GdImage
    {
        $palette = $this->palette($hex);

        // The wash is composed at 1/8 scale and scaled up. Resampling a small
        // canvas is what produces the soft out-of-focus falloff — drawing the
        // same ellipses at full size would give hard, obviously-vector edges,
        // and at 1/4 the blob rims were still legible as circles.
        $im = $this->wash($palette, (int) round($w / 8), (int) round($h / 8), $w, $h);

        imagealphablending($im, true);

        $this->strokes($im, $palette, $w, $h);
        $this->texture($im, $palette, $w, $h);
        $this->shafts($im, $palette, $w, $h);
        $this->grain($im, $w, $h, 14);
        $this->vignette($im, $w, $h, 62);

        return $im;
    }

    /**
     * A house ad creative. Flat, high-contrast and labelled with its own
     * dimensions so a broken slot is obvious on sight.
     */
    public function creative(string $hex, int $w, int $h, string $label): \GdImage
    {
        $palette = $this->palette($hex);

        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);

        $this->linearGradient($im, $w, $h, $palette['deep'], $palette['mid']);

        // Diagonal sweep, clipped by the canvas.
        $sweep = $this->colour($im, $palette['accent'], 58);
        $span = (int) round($w * 0.45);
        imagefilledpolygon($im, [
            0, $h,
            $span, 0,
            $span + (int) round($w * 0.18), 0,
            (int) round($w * 0.18), $h,
        ], $sweep);

        $this->grain($im, $w, $h, 8);

        $inset = max(2, (int) round(min($w, $h) * 0.04));
        imagesetthickness($im, max(1, (int) round(min($w, $h) * 0.015)));
        imagerectangle($im, $inset, $inset, $w - 1 - $inset, $h - 1 - $inset,
            $this->colour($im, $palette['highlight'], 70));
        imagesetthickness($im, 1);

        $this->label($im, $w, $h, $label, $palette);

        return $im;
    }

    /**
     * A broadsheet page, portrait, as it reads at thumbnail size.
     *
     * Deliberately not a photograph: the e-paper grid is a wall of pages, and
     * what has to be legible at 200px is the *shape* of a newspaper — masthead,
     * column rules, headline bars, a picture block. Text is drawn as grey rules
     * rather than set in a face, because at this size real Bangla would be a
     * smear and at full size it would be lorem nobody asked for.
     *
     * `$front` gives page one its masthead and a wider lead; inside pages get
     * a section strip instead.
     *
     * The masthead carries no words on purpose. GD's TTF renderer does no
     * complex shaping, so Bangla comes out with its conjuncts unformed and its
     * vowel signs in logical rather than visual order — and the one font on
     * this box that has the glyphs at all renders দৈনিক wrong rather than not
     * at all. A wrong Bangla nameplate in front of a Bangla newsroom is worse
     * than a nameplate-shaped block, and at the 200px the grid shows it, the
     * shape is the whole signal.
     */
    public function newspaperPage(string $hex, int $w, int $h, bool $front = false): \GdImage
    {
        $palette = $this->palette($hex);

        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);

        // Newsprint, not white: a page that reads as paper against the grid's
        // surface colour rather than as a hole in it.
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 246, 244, 238));

        $ink = imagecolorallocate($im, 28, 28, 30);
        $rule = imagecolorallocate($im, 168, 166, 160);
        $text = imagecolorallocate($im, 122, 120, 116);

        $margin = (int) round($w * 0.06);
        $inner = $w - 2 * $margin;
        $y = $margin;

        if ($front) {
            // Masthead: a solid bar in the section colour with a nameplate
            // block centred in it, and a hairline under.
            $bar = (int) round($h * 0.055);
            imagefilledrectangle($im, $margin, $y, $margin + $inner, $y + $bar,
                $this->colour($im, $palette['deep'], 100));

            $plateWidth = (int) round($inner * 0.42);
            $plateHeight = (int) round($bar * 0.42);
            $plateX = $margin + (int) round(($inner - $plateWidth) / 2);
            $plateY = $y + (int) round(($bar - $plateHeight) / 2);
            imagefilledrectangle($im, $plateX, $plateY, $plateX + $plateWidth, $plateY + $plateHeight,
                $this->colour($im, $palette['highlight'], 92));

            $y += $bar + (int) round($h * 0.012);
        } else {
            $strip = (int) round($h * 0.018);
            imagefilledrectangle($im, $margin, $y, $margin + (int) round($inner * 0.34), $y + $strip,
                $this->colour($im, $palette['accent'], 100));
            $y += $strip + (int) round($h * 0.014);
        }

        imagefilledrectangle($im, $margin, $y, $margin + $inner, $y + 2, $ink);
        $y += (int) round($h * 0.016);

        // Lead headline: two or three heavy bars, ragged right like real type.
        foreach (range(1, $front ? 3 : 2) as $line) {
            $height = (int) round($h * ($front ? 0.030 : 0.022));
            $width = (int) round($inner * $this->rand(62, 100) / 100);
            imagefilledrectangle($im, $margin, $y, $margin + $width, $y + $height, $ink);
            $y += $height + (int) round($h * 0.010);
        }

        $y += (int) round($h * 0.008);

        // Picture block, then columns filling whatever is left.
        $picture = (int) round($h * ($front ? 0.26 : 0.20));
        $pictureWidth = (int) round($inner * ($front ? 1.0 : 0.62));

        // Composed larger than the ad creative's wash: this one is shown at
        // page size, where a 40px source reads as visible blocks.
        $wash = $this->wash($palette, 90, 68, $pictureWidth, $picture);
        imagecopy($im, $wash, $margin, $y, 0, 0, $pictureWidth, $picture);
        imagedestroy($wash);

        imagerectangle($im, $margin, $y, $margin + $pictureWidth, $y + $picture, $rule);

        // Caption rule under the picture.
        $y += $picture + (int) round($h * 0.008);
        imagefilledrectangle($im, $margin, $y, $margin + (int) round($pictureWidth * 0.7), $y + 2, $text);
        $y += (int) round($h * 0.016);

        $this->columns($im, $margin, $y, $inner, $h - $y - $margin, $front ? 4 : 3, $text, $rule);

        $this->grain($im, $w, $h, 5);

        return $im;
    }

    /** Body text as column rules, with a gutter hairline between each pair. */
    private function columns(\GdImage $im, int $x, int $y, int $width, int $height, int $count, int $text, int $rule): void
    {
        if ($height < 20) {
            return;
        }

        $gutter = (int) round($width * 0.03);
        $column = (int) round(($width - $gutter * ($count - 1)) / $count);
        $line = max(2, (int) round($height * 0.016));
        $gap = max(2, (int) round($line * 0.9));

        for ($c = 0; $c < $count; $c++) {
            $left = $x + $c * ($column + $gutter);
            $cursor = $y;

            if ($c > 0) {
                imageline($im, $left - (int) round($gutter / 2), $y,
                    $left - (int) round($gutter / 2), $y + $height, $rule);
            }

            while ($cursor + $line < $y + $height) {
                // A short line every so often reads as the end of a paragraph.
                $length = $this->rand(1, 10) === 1
                    ? (int) round($column * $this->rand(30, 60) / 100)
                    : $column;

                imagefilledrectangle($im, $left, $cursor, $left + $length, $cursor + $line - 1, $text);
                $cursor += $line + $gap;
            }
        }
    }

    // ── Layers ───────────────────────────────────────────────────────────

    /** Blurred colour field: gradient plus soft blobs, resampled up. */
    private function wash(array $palette, int $sw, int $sh, int $w, int $h): \GdImage
    {
        $small = imagecreatetruecolor($sw, $sh);
        imagealphablending($small, true);

        $this->linearGradient($small, $sw, $sh, $palette['deep'], $palette['mid']);

        $tones = [$palette['mid'], $palette['accent'], $palette['light'], $palette['highlight'], $palette['deep']];

        for ($i = 0; $i < 72; $i++) {
            $tone = $tones[$this->rand(0, count($tones) - 1)];
            $rx = $this->rand((int) round($sw * 0.06), (int) round($sw * 0.42));
            $ry = (int) round($rx * $this->rand(60, 140) / 100);

            imagefilledellipse(
                $small,
                $this->rand(-(int) round($sw * 0.1), $sw + (int) round($sw * 0.1)),
                $this->rand(-(int) round($sh * 0.1), $sh + (int) round($sh * 0.1)),
                $rx * 2,
                $ry * 2,
                $this->colour($small, $tone, $this->rand(12, 30)),
            );
        }

        // Upscaling alone still leaves every blob rim readable as an arc.
        // Blurring the small canvas first is what turns them into depth of
        // field, and at 1/8 scale it costs nothing.
        for ($i = 0; $i < 3; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        $im = imagecreatetruecolor($w, $h);
        imagecopyresampled($im, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($small);

        return $im;
    }

    /**
     * Mid-frequency detail at full resolution.
     *
     * This is the layer that makes the ladder worth measuring. The wash is
     * gone by the time it is downscaled to 640px; these survive, so the small
     * rungs still cost realistic bytes instead of collapsing to a gradient.
     */
    private function strokes(\GdImage $im, array $palette, int $w, int $h): void
    {
        $tones = [$palette['light'], $palette['highlight'], $palette['deep'], $palette['accent']];

        for ($i = 0; $i < 520; $i++) {
            $tone = $tones[$this->rand(0, count($tones) - 1)];
            $x = $this->rand(0, $w);
            $y = $this->rand(0, $h);
            $len = $this->rand((int) round($w * 0.01), (int) round($w * 0.09));
            $angle = $this->rand(0, 359) * M_PI / 180;

            imagesetthickness($im, $this->rand(1, 4));
            imageline(
                $im,
                $x, $y,
                $x + (int) round(cos($angle) * $len),
                $y + (int) round(sin($angle) * $len),
                $this->colour($im, $tone, $this->rand(6, 26)),
            );
        }

        imagesetthickness($im, 1);

        for ($i = 0; $i < 90; $i++) {
            $r = $this->rand((int) round($w * 0.004), (int) round($w * 0.03));

            imagefilledellipse(
                $im,
                $this->rand(0, $w),
                $this->rand(0, $h),
                $r * 2,
                $r * 2,
                $this->colour($im, $palette['highlight'], $this->rand(5, 18)),
            );
        }
    }

    /**
     * Fine texture, sized to survive the downscale.
     *
     * Grain is too high a frequency to matter here: resampling 2400px to 640px
     * averages 3.75 source pixels into one and most of it disappears. Detail
     * has to exist at 4-20px in the source to still be there at 640, so this
     * pass lays down thousands of marks in that band. Without it the lower
     * rungs collapse to a few kilobytes and every byte-weight measurement
     * Phase 6 wants to take comes out flattering and wrong.
     */
    private function texture(\GdImage $im, array $palette, int $w, int $h): void
    {
        // `deep` is deliberately absent: against a light wash it read as
        // pepper, not as photographic detail.
        $tones = [$palette['light'], $palette['highlight'], $palette['accent'], $palette['mid']];

        // Density scales with area, so a 300x250 ad creative is not peppered
        // with the mark count a 2400x1350 plate wants. The count is high
        // because the contrast is low — quiet marks, many of them.
        $marks = (int) round($w * $h / 430);

        for ($i = 0; $i < $marks; $i++) {
            $tone = $tones[$this->rand(0, count($tones) - 1)];
            $r = $this->rand(2, 10);

            imagefilledellipse(
                $im,
                $this->rand(0, $w),
                $this->rand(0, $h),
                $r * 2,
                (int) round($r * 2 * $this->rand(60, 140) / 100),
                $this->colour($im, $tone, $this->rand(7, 27)),
            );
        }

        $flecks = (int) round($marks * 0.7);

        for ($i = 0; $i < $flecks; $i++) {
            $tone = $tones[$this->rand(0, count($tones) - 1)];
            $x = $this->rand(0, $w);
            $y = $this->rand(0, $h);
            $len = $this->rand(5, 26);
            $angle = $this->rand(0, 359) * M_PI / 180;

            imagesetthickness($im, $this->rand(1, 3));
            imageline(
                $im,
                $x, $y,
                $x + (int) round(cos($angle) * $len),
                $y + (int) round(sin($angle) * $len),
                $this->colour($im, $tone, $this->rand(8, 29)),
            );
        }

        imagesetthickness($im, 1);
    }

    /** One or two soft light shafts, for a focal point the eye can land on. */
    private function shafts(\GdImage $im, array $palette, int $w, int $h): void
    {
        for ($i = 0, $n = $this->rand(1, 2); $i < $n; $i++) {
            $x = $this->rand(0, $w);
            $width = $this->rand((int) round($w * 0.08), (int) round($w * 0.22));
            $skew = $this->rand(-(int) round($w * 0.25), (int) round($w * 0.25));

            imagefilledpolygon($im, [
                $x, 0,
                $x + $width, 0,
                $x + $width + $skew, $h,
                $x + $skew, $h,
            ], $this->colour($im, $palette['highlight'], $this->rand(6, 14)));
        }
    }

    /**
     * Film grain, merged from a tile shared across the whole run.
     *
     * The tile is drawn at half size and nearest-neighbour scaled up, which is
     * ~4x cheaper than per-pixel work at full resolution and still leaves
     * 2x2-pixel structure — high enough frequency to cost the encoder real
     * bits.
     */
    private function grain(\GdImage $im, int $w, int $h, int $percent): void
    {
        $tile = self::noiseTile();

        imagecopymerge(
            $im,
            $tile,
            0, 0,
            $this->rand(0, max(0, self::$noiseW - $w)),
            $this->rand(0, max(0, self::$noiseH - $h)),
            min($w, self::$noiseW),
            min($h, self::$noiseH),
            $percent,
        );
    }

    /**
     * Radial falloff, built small and resampled up.
     *
     * The first cut drew nested rectangles inward from the edge. That is not a
     * vignette, it is a picture frame — the corners stay square and the
     * boundary is visible as a hard line. A radial alpha mask costs 14k pixels
     * to build and actually looks like a lens.
     */
    private function vignette(\GdImage $im, int $w, int $h, int $strength): void
    {
        $sw = 160;
        $sh = max(2, (int) round($sw * $h / $w));

        $mask = imagecreatetruecolor($sw, $sh);
        imagealphablending($mask, false);
        imagesavealpha($mask, true);

        $cx = ($sw - 1) / 2;
        $cy = ($sh - 1) / 2;
        $max = sqrt($cx ** 2 + $cy ** 2);

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $max;
                $t = max(0.0, ($d - 0.42) / 0.58);
                $alpha = (int) round($strength * $t ** 1.7);

                imagesetpixel($mask, $x, $y,
                    imagecolorallocatealpha($mask, 0, 0, 0, 127 - min(127, $alpha)));
            }
        }

        imagealphablending($im, true);
        imagecopyresampled($im, $mask, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($mask);
    }

    private function label(\GdImage $im, int $w, int $h, string $label, array $palette): void
    {
        if (! is_readable(self::FONT)) {
            return;   // Optional: the creative reads fine as a colour block.
        }

        $size = max(7, min((int) round($h * 0.16), (int) round($w * 0.07)));
        $box = imagettfbbox($size, 0, self::FONT, $label);
        $tw = $box[2] - $box[0];
        $th = $box[1] - $box[7];

        imagettftext(
            $im, $size, 0,
            (int) round(($w - $tw) / 2),
            (int) round(($h + $th) / 2),
            $this->colour($im, $palette['highlight'], 100),
            self::FONT,
            $label,
        );
    }

    // ── Primitives ───────────────────────────────────────────────────────

    private function linearGradient(\GdImage $im, int $w, int $h, array $from, array $to): void
    {
        imagealphablending($im, false);

        for ($y = 0; $y < $h; $y++) {
            $t = $h > 1 ? $y / ($h - 1) : 0;

            imagefilledrectangle($im, 0, $y, $w - 1, $y, imagecolorallocate(
                $im,
                (int) round($from[0] + ($to[0] - $from[0]) * $t),
                (int) round($from[1] + ($to[1] - $from[1]) * $t),
                (int) round($from[2] + ($to[2] - $from[2]) * $t),
            ));
        }

        imagealphablending($im, true);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function colour(\GdImage $im, array $rgb, int $opacityPercent): int
    {
        return imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2],
            (int) round(127 - 127 * min(100, max(0, $opacityPercent)) / 100));
    }

    private static function noiseTile(): \GdImage
    {
        if (self::$noise instanceof \GdImage) {
            return self::$noise;
        }

        self::$noiseW = 2600;
        self::$noiseH = 1500;

        $hw = (int) (self::$noiseW / 2);
        $hh = (int) (self::$noiseH / 2);

        $half = imagecreatetruecolor($hw, $hh);
        imagealphablending($half, false);

        // Monochrome grain centred on mid-grey so imagecopymerge neither
        // brightens nor darkens the plate overall.
        for ($y = 0; $y < $hh; $y++) {
            for ($x = 0; $x < $hw; $x++) {
                $v = 128 + random_int(-72, 72);
                imagesetpixel($half, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        $tile = imagecreatetruecolor(self::$noiseW, self::$noiseH);
        // Deliberately imagecopyresized, not resampled: resampling would
        // average the grain away, which is the opposite of the point.
        imagecopyresized($tile, $half, 0, 0, 0, 0, self::$noiseW, self::$noiseH, $hw, $hh);
        imagedestroy($half);

        return self::$noise = $tile;
    }

    public static function releaseNoise(): void
    {
        if (self::$noise instanceof \GdImage) {
            imagedestroy(self::$noise);
            self::$noise = null;
        }
    }

    // ── Colour ───────────────────────────────────────────────────────────

    /**
     * Five tones derived from a section's brand hex.
     *
     * @return array<string, array{0:int,1:int,2:int}>
     */
    private function palette(string $hex): array
    {
        [$h, $s, $l] = self::hexToHsl($hex);

        return [
            'deep' => self::hslToRgb($h + $this->rand(-12, 12) / 360, min(1, $s * 0.7), 0.11),
            'mid' => self::hslToRgb($h, min(1, $s * 0.6), 0.30),
            'accent' => self::hslToRgb($h + $this->rand(-8, 8) / 360, min(1, $s * 0.75), 0.48),
            'light' => self::hslToRgb($h + $this->rand(10, 45) / 360, min(1, $s * 0.45), 0.62),
            'highlight' => self::hslToRgb($h - $this->rand(5, 35) / 360, min(1, $s * 0.3), 0.82),
        ];
    }

    /** @return array{0:float,1:float,2:float} */
    private static function hexToHsl(string $hex): array
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d == 0) {
            return [0.0, 0.0, $l];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    /** @return array{0:int,1:int,2:int} */
    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod(fmod($h, 1) + 1, 1);

        if ($s == 0) {
            $v = (int) round($l * 255);

            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return [
            (int) round(self::hue($p, $q, $h + 1 / 3) * 255),
            (int) round(self::hue($p, $q, $h) * 255),
            (int) round(self::hue($p, $q, $h - 1 / 3) * 255),
        ];
    }

    private static function hue(float $p, float $q, float $t): float
    {
        $t = fmod(fmod($t, 1) + 1, 1);

        return match (true) {
            $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
            default => $p,
        };
    }

    // ── Deterministic RNG ────────────────────────────────────────────────

    private function rand(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }

        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $min + $this->state % ($max - $min + 1);
    }
}
