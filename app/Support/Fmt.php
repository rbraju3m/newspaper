<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What the `@bn*` directives actually call, now that there are two editions.
 *
 * **The directive names did not change, and that is the point.** `@bn`,
 * `@bndate`, `@bntime`, `@bnago`, `@bncount` and `@bnfulldate` appear a few
 * hundred times across 115 templates; renaming them to something
 * locale-neutral would be a diff touching nearly every view for no behaviour
 * at all, and every one of those touches is a chance to break a page. They
 * read as "the site's number and date formatting", which is what they have
 * always been — the site simply has a second edition now.
 *
 * `Bangla` stays exactly as it was: pure, Bangla-only, and still the thing
 * its own tests exercise. This is the switch in front of it, so nothing in
 * the Bangla path changed shape and `BanglaTest` still proves what it proved.
 *
 * The English side is not a translation of the Bangla side. Three of these
 * differ in kind rather than in wording:
 *
 * - **Time.** Bangla names the part of the day — `রাত ৯:৪৫` — and there is no
 *   AM/PM in it. English gets `9:45 PM`, because "night 9:45" is not English.
 * - **Compact counts.** Bangla counts in লাখ and কোটি, which are 10⁵ and 10⁷.
 *   English gets K and M at 10³ and 10⁶. Converting the words while keeping
 *   the Bangla thresholds would print "0.1M" where a reader expects "100K".
 * - **The masthead date.** The Bangla line carries the Bengali calendar
 *   (`১০ ভাদ্র ১৪৩৩ বঙ্গাব্দ`), which is a Bangladeshi reader's second
 *   calendar and nobody else's. The English line drops it rather than
 *   transliterating a date that would mean nothing to the reader it is for.
 */
class Fmt
{
    private static function bangla(): bool
    {
        return Locale::isDefault();
    }

    /** Digits in the edition's own numerals, thousands-separated in English. */
    public static function digits(string|int|float|null $value): string
    {
        if (self::bangla()) {
            return Bangla::digits($value);
        }

        return is_int($value) || is_float($value)
            ? number_format((float) $value, is_float($value) && fmod($value, 1.0) !== 0.0 ? 1 : 0)
            : (string) $value;
    }

    /** "২৫ আগস্ট ২০২৬" / "25 August 2026" */
    public static function date(CarbonInterface $date): string
    {
        return self::bangla() ? Bangla::date($date) : $date->format('j F Y');
    }

    /** "রাত ৯:৪৫" / "9:45 PM" — see the class docblock on why these differ. */
    public static function time(CarbonInterface $date): string
    {
        return self::bangla() ? Bangla::time($date) : $date->format('g:i A');
    }

    /** "মঙ্গলবার" / "Tuesday" */
    public static function weekday(CarbonInterface $date): string
    {
        return self::bangla() ? Bangla::weekday($date) : $date->format('l');
    }

    /** "মঙ্গলবার, ২৫ আগস্ট ২০২৬, ১০ ভাদ্র ১৪৩৩ বঙ্গাব্দ" / "Tuesday, 25 August 2026" */
    public static function fullDate(?CarbonInterface $date = null): string
    {
        $date ??= Carbon::now();

        return self::bangla() ? Bangla::fullDate($date) : $date->format('l, j F Y');
    }

    /** "৩৮ মিনিট আগে" / "38 minutes ago" */
    public static function ago(CarbonInterface $date): string
    {
        if (self::bangla()) {
            return Bangla::ago($date);
        }

        $now = Carbon::now();

        if ($date->gt($now)) {
            return self::date($date);
        }

        $seconds = (int) $date->diffInSeconds($now);

        $unit = fn (int $n, string $word): string => $n.' '.$word.($n === 1 ? '' : 's').' ago';

        return match (true) {
            $seconds < 60 => 'just now',
            $seconds < 3600 => $unit((int) floor($seconds / 60), 'minute'),
            $seconds < 86400 => $unit((int) floor($seconds / 3600), 'hour'),
            $seconds < 604800 => $unit((int) floor($seconds / 86400), 'day'),
            default => self::date($date),
        };
    }

    /** "১.২ হাজার" / "1.2K" — different scales, not a translation. */
    public static function compact(int $n): string
    {
        if (self::bangla()) {
            return Bangla::compact($n);
        }

        return match (true) {
            $n >= 1_000_000 => rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.').'M',
            $n >= 1_000 => rtrim(rtrim(number_format($n / 1_000, 1), '0'), '.').'K',
            default => number_format($n),
        };
    }
}
