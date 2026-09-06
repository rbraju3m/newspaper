<?php

namespace Tests\Unit;

use App\Support\Contrast;
use PHPUnit\Framework\TestCase;

/**
 * `Contrast` — the WCAG ratio, and the smallest legible shade of a colour.
 *
 * The ratios asserted here are **published constants, not values this class
 * produced**. `#767676` on white is the canonical lightest grey that clears
 * AA at 4.54:1 and `#777777` the one just under it at 4.48:1; black on white
 * is 21:1 by definition. Asserting against numbers computed by the code under
 * test would pass on any luminance formula at all, which is the shape
 * `AvatarTest` avoids by keeping its own implementation — that second
 * implementation is deliberately left in place as a cross-check rather than
 * folded into this one.
 */
class ContrastTest extends TestCase
{
    // ── The ratio ────────────────────────────────────────────────────────

    public function test_it_computes_the_published_ratios(): void
    {
        $this->assertSame(21.0, round(Contrast::ratio('#000000'), 2));
        $this->assertSame(1.0, round(Contrast::ratio('#FFFFFF'), 2));
        $this->assertSame(4.54, round(Contrast::ratio('#767676'), 2));
        $this->assertSame(4.48, round(Contrast::ratio('#777777'), 2));
    }

    /** The two seeded category colours this whole change exists for. */
    public function test_the_two_failing_category_colours_are_below_aa(): void
    {
        $this->assertSame(3.43, round(Contrast::ratio('#DB6B00'), 2));
        $this->assertSame(3.68, round(Contrast::ratio('#0891B2'), 2));
    }

    public function test_the_ratio_does_not_depend_on_which_colour_is_named_first(): void
    {
        $this->assertSame(
            Contrast::ratio('#0E7C66', '#FFFFFF'),
            Contrast::ratio('#FFFFFF', '#0E7C66'),
        );
    }

    /**
     * A value it cannot read must never look legible. 1.0 is no contrast at
     * all, so every `>= target` test in the class fails closed.
     */
    public function test_an_unreadable_value_has_no_contrast(): void
    {
        $this->assertSame(1.0, Contrast::ratio('nonsense'));
        $this->assertSame(1.0, Contrast::ratio('#FFFFFF', 'rgb(0,0,0)'));
    }

    // ── What it leaves alone ─────────────────────────────────────────────

    /**
     * Sixteen of the eighteen seeded colours already clear AA, and the point
     * of computing rather than substituting is that those are not repainted.
     * Returned verbatim, casing included — a lowercased return would show up
     * as a diff in every digest for no reason.
     */
    public function test_a_colour_that_already_clears_the_target_is_returned_verbatim(): void
    {
        $this->assertSame('#C8102E', Contrast::readable('#C8102E'));
        $this->assertSame('#c8102e', Contrast::readable('#c8102e'));
    }

    /** It darkens colours; validating them is not its job and must not throw. */
    public function test_a_value_it_cannot_parse_is_returned_untouched(): void
    {
        $this->assertSame('nonsense', Contrast::readable('nonsense'));
        $this->assertSame('#GGGGGG', Contrast::readable('#GGGGGG'));
        $this->assertSame('', Contrast::readable(''));
    }

    public function test_it_understands_shorthand_and_a_missing_hash(): void
    {
        $this->assertSame('#f80', Contrast::readable('#f80', '#14171A'), 'Shorthand was not parsed.');
        $this->assertSame('DB6B00', Contrast::readable('DB6B00', '#14171A'), 'A missing hash was not parsed.');

        // Both fail on white, so both must move — which they cannot do unless
        // they parsed. The assertions above only prove the passing branch.
        $this->assertNotSame('#f80', Contrast::readable('#f80'));
        $this->assertNotSame('DB6B00', Contrast::readable('DB6B00'));
    }

    // ── What it changes ──────────────────────────────────────────────────

    public function test_a_failing_colour_is_moved_until_it_clears_aa(): void
    {
        foreach (['#DB6B00', '#0891B2'] as $hex) {
            $fixed = Contrast::readable($hex);

            $this->assertNotSame($hex, $fixed);
            $this->assertGreaterThanOrEqual(Contrast::AA, Contrast::ratio($fixed),
                "{$hex} was moved to {$fixed}, which still does not clear AA.");
        }
    }

    /**
     * Only as far as it has to go. Returning black would clear AA at 21:1 and
     * pass every other assertion in this file while throwing the category's
     * colour away — which is the whole thing being preserved.
     */
    public function test_it_stops_at_the_first_shade_that_clears_the_target(): void
    {
        $fixed = Contrast::readable('#DB6B00');

        $this->assertLessThan(4.8, Contrast::ratio($fixed),
            "Overshot to {$fixed}: the label should be the lightest shade that clears AA.");
    }

    /**
     * A uniform mix toward black keeps the ratios between the channels, so
     * `#DB6B00` comes back a darker orange rather than some other colour. A
     * label that changes hue is a label in the wrong section's colour.
     */
    public function test_the_hue_survives_the_darkening(): void
    {
        foreach (['#DB6B00', '#0891B2'] as $hex) {
            $this->assertEqualsWithDelta(
                $this->hue($hex), $this->hue(Contrast::readable($hex)), 1.0,
                "{$hex} changed hue on its way to legibility.",
            );
        }
    }

    // ── Direction, and the case with no answer ───────────────────────────

    /**
     * The move is away from the background, not unconditionally darker. On a
     * dark surface a too-dark colour has to be lightened, and a helper that
     * only ever darkens would make it worse while reporting success.
     */
    public function test_on_a_dark_background_a_failing_colour_is_lightened(): void
    {
        $fixed = Contrast::readable('#2A2A2A', '#14171A');

        $this->assertGreaterThanOrEqual(Contrast::AA, Contrast::ratio($fixed, '#14171A'));
        $this->assertGreaterThan(
            $this->luminance('#2A2A2A'), $this->luminance($fixed),
            'It darkened a colour that was already too dark for its background.',
        );
    }

    /**
     * A mid grey defeats both black and white at AAA. The most legible answer
     * available beats returning the unreadable one that was asked for.
     */
    public function test_an_unreachable_target_returns_the_better_endpoint(): void
    {
        $this->assertLessThan(7.0, Contrast::ratio('#000000', '#808080'));
        $this->assertLessThan(7.0, Contrast::ratio('#FFFFFF', '#808080'));

        $this->assertSame('#000000', Contrast::readable('#DB6B00', '#808080', 7.0));
    }

    // ── Oracles, written here rather than borrowed from the subject ──────

    /** HSL hue in degrees. */
    private function hue(string $hex): float
    {
        [$r, $g, $b] = array_map(fn (string $p): float => hexdec($p) / 255, str_split(ltrim($hex, '#'), 2));

        $max = max($r, $g, $b);
        $chroma = $max - min($r, $g, $b);

        if ($chroma === 0.0) {
            return 0.0;
        }

        $h = match ($max) {
            $r => fmod(($g - $b) / $chroma, 6),
            $g => ($b - $r) / $chroma + 2,
            default => ($r - $g) / $chroma + 4,
        };

        return fmod($h * 60 + 360, 360);
    }

    /** WCAG relative luminance, for comparing two shades of the same colour. */
    private function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (string $pair): float {
            $v = hexdec($pair) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
