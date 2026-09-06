<?php

namespace App\Support;

/**
 * WCAG contrast, and the smallest shade of a colour that clears it.
 *
 * The newsletter digest prints a section name in that section's own colour on
 * white. Two of the eighteen seeded category colours do not carry small text
 * at WCAG AA — `#DB6B00` (জীবনযাপন, and the three sections under it) at
 * 3.43:1 and `#0891B2` (স্বাস্থ্য) at 3.68:1 — so five real sections arrived
 * in the inbox as a label a reader with anything less than good sight cannot
 * read. `Avatar` met the same colour and answered by leaving it out of its
 * palette; a category label cannot, because the colour is the category's.
 *
 * **Darkened rather than substituted, and not hard-coded.** `categories.color`
 * is an `<input type="color">` in the admin, so any table of known-bad values
 * is one editor away from being wrong. This computes, which means a colour
 * picked next year is handled the same as the two that shipped.
 *
 * **The move is a uniform mix toward black or white**, which preserves hue and
 * HSL saturation exactly — `#DB6B00` becomes a darker `#DB6B00` and not some
 * other orange. It steps one 255th at a time and returns the *first* shade
 * that clears the target, so the label stays as close to the editor's colour
 * as legibility allows.
 *
 * Where this is deliberately quiet:
 *
 * - **A colour that already clears the target is returned verbatim**, casing
 *   and all. Sixteen of the eighteen go through untouched.
 * - **So is one it cannot parse.** This darkens colours; it does not validate
 *   them, and a malformed value in one row must not cost every subscriber
 *   their digest. The caller's own fallback (`?: '#C8102E'`) covers null.
 */
class Contrast
{
    /** WCAG AA for text below 18.66px bold / 24px regular, which every label here is. */
    public const AA = 4.5;

    /**
     * The two surfaces a section label is printed on, and the reason there
     * are two.
     *
     * These are `--color-surface` from `resources/css/app.css`, light and
     * dark. `--color-canvas` is not used even though some cards sit on it,
     * because in both themes it is the *easier* background: on light it is
     * `#F7F8FA`, darker than white and so kinder to dark text, and on dark it
     * is `#0E1113`, darker than the surface and so kinder to light text.
     * Solving for the surface is conservative in both directions.
     *
     * `ContrastTest` reads both values back out of `app.css`, because these
     * are a copy of somebody else's numbers and a theme edit would otherwise
     * leave every label computed against a background that no longer exists.
     */
    public const SURFACE_LIGHT = '#FFFFFF';

    public const SURFACE_DARK = '#171A1D';

    private const WHITE = [255, 255, 255];

    private const BLACK = [0, 0, 0];

    /**
     * Memo for `readable()`, which is called once per card per theme.
     *
     * A homepage renders around a hundred cards drawn from a handful of
     * sections, so this turns two hundred scans into a dozen. Safe because
     * the function is pure, and bounded by the number of distinct colours an
     * editor has picked.
     *
     * @var array<string, string>
     */
    private static array $memo = [];

    /**
     * The WCAG relative-luminance contrast ratio between two colours, 1–21.
     *
     * Returns 1.0 — no contrast at all, and therefore never "passing" — for
     * anything it cannot parse, so a garbled value can never read as legible.
     */
    public static function ratio(string $hex, string $against = '#FFFFFF'): float
    {
        $a = self::rgb($hex);
        $b = self::rgb($against);

        if ($a === null || $b === null) {
            return 1.0;
        }

        $light = max(self::luminance($a), self::luminance($b));
        $dark = min(self::luminance($a), self::luminance($b));

        return ($light + 0.05) / ($dark + 0.05);
    }

    /**
     * `$hex`, moved away from `$on` only as far as `$target` requires.
     *
     * The direction is whichever endpoint — black or white — contrasts more
     * with the background, so this is correct on a dark surface as well as on
     * the email's white one. If neither endpoint reaches the target (a
     * mid-grey background can defeat both), the better endpoint is returned
     * rather than nothing: the most legible answer available beats the
     * unreadable one that was asked for.
     */
    public static function readable(string $hex, string $on = '#FFFFFF', float $target = self::AA): string
    {
        return self::$memo[$hex.'|'.$on.'|'.$target] ??= self::compute($hex, $on, $target);
    }

    private static function compute(string $hex, string $on, float $target): string
    {
        $rgb = self::rgb($hex);
        $background = self::rgb($on);

        if ($rgb === null || $background === null) {
            return $hex;
        }

        if (self::ratio($hex, $on) >= $target) {
            return $hex;
        }

        $towards = self::ratio('#000000', $on) >= self::ratio('#FFFFFF', $on)
            ? self::BLACK
            : self::WHITE;

        // Monotonic, so the first shade that clears the target is the closest
        // one to the colour the editor chose.
        for ($step = 1; $step <= 255; $step++) {
            $candidate = self::hex(self::mix($rgb, $towards, $step / 255));

            if (self::ratio($candidate, $on) >= $target) {
                return $candidate;
            }
        }

        return self::hex($towards);
    }

    /**
     * The declarations that make one editor-picked colour legible on both
     * themes, for a `style` attribute.
     *
     * **Two custom properties rather than one computed colour, because the
     * server does not know which theme the reader is in.** The theme is a
     * class on `<html>` that Alpine sets from `localStorage` — so a single
     * value computed here would be right for one theme and wrong for the
     * other, and on this palette *wrong* is the common case: sixteen of the
     * eighteen seeded category colours fail AA on the dark surface, against
     * two on the light one. `.section-label` in `app.css` picks between them.
     */
    public static function labelStyle(?string $hex, string $fallback = '#C8102E'): string
    {
        $hex = $hex ?: $fallback;

        return '--label-light:'.self::readable($hex, self::SURFACE_LIGHT)
            .';--label-dark:'.self::readable($hex, self::SURFACE_DARK);
    }

    /** `$rgb` moved `$amount` of the way to `$towards`, which preserves hue. */
    private static function mix(array $rgb, array $towards, float $amount): array
    {
        return array_map(
            fn (int $channel, int $end): int => (int) round($channel + ($end - $channel) * $amount),
            $rgb,
            $towards,
        );
    }

    /** `#rgb`, `#rrggbb` or either without the hash; null for anything else. */
    private static function rgb(string $hex): ?array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return array_map('hexdec', str_split($hex, 2));
    }

    private static function hex(array $rgb): string
    {
        return '#'.strtoupper(vsprintf('%02x%02x%02x', $rgb));
    }

    /** WCAG relative luminance: sRGB channels linearised, then weighted. */
    private static function luminance(array $rgb): float
    {
        [$r, $g, $b] = array_map(function (int $value): float {
            $value /= 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
