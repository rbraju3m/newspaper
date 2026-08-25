<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Bangla presentation helpers.
 *
 * Every reference paper (Prothom Alo, mzamin, Naya Diganta, Ittefaq) renders
 * numbers in Bangla digits and shows the Bengali calendar date alongside the
 * Gregorian one, so these are needed site-wide rather than in one view.
 */
class Bangla
{
    private const DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** Gregorian month names in Bangla. */
    private const MONTHS = [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
        5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];

    /** 0 = Sunday, matching Carbon::dayOfWeek. */
    private const WEEKDAYS = [
        0 => 'রবিবার', 1 => 'সোমবার', 2 => 'মঙ্গলবার', 3 => 'বুধবার',
        4 => 'বৃহস্পতিবার', 5 => 'শুক্রবার', 6 => 'শনিবার',
    ];

    /** Bengali (Bangladeshi revised) calendar months. */
    private const BN_MONTHS = [
        1 => 'বৈশাখ', 2 => 'জ্যৈষ্ঠ', 3 => 'আষাঢ়', 4 => 'শ্রাবণ',
        5 => 'ভাদ্র', 6 => 'আশ্বিন', 7 => 'কার্তিক', 8 => 'অগ্রহায়ণ',
        9 => 'পৌষ', 10 => 'মাঘ', 11 => 'ফাল্গুন', 12 => 'চৈত্র',
    ];

    /** Convert ASCII digits inside any string to Bangla digits. */
    public static function digits(string|int|float|null $value): string
    {
        return strtr((string) $value, array_combine(range('0', '9'), self::DIGITS));
    }

    /** "২৫ আগস্ট ২০২৬" */
    public static function date(CarbonInterface $date): string
    {
        return self::digits($date->day).' '.self::MONTHS[$date->month].' '.self::digits($date->year);
    }

    /** "মঙ্গলবার" */
    public static function weekday(CarbonInterface $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeek];
    }

    /** "রাত ৯:৪৫" — Bangla day-part naming, which does not map to AM/PM. */
    public static function time(CarbonInterface $date): string
    {
        $h = (int) $date->format('G');

        $part = match (true) {
            $h < 4 => 'রাত',
            $h < 6 => 'ভোর',
            $h < 12 => 'সকাল',
            $h < 15 => 'দুপুর',
            $h < 18 => 'বিকেল',
            $h < 20 => 'সন্ধ্যা',
            default => 'রাত',
        };

        return $part.' '.self::digits($date->format('g:i'));
    }

    /** Full masthead line: "মঙ্গলবার, ২৫ আগস্ট ২০২৬, ১০ ভাদ্র ১৪৩৩ বঙ্গাব্দ" */
    public static function fullDate(?CarbonInterface $date = null): string
    {
        $date = $date ?? Carbon::now();

        return self::weekday($date).', '.self::date($date).', '.self::bengaliDate($date);
    }

    /**
     * Bengali calendar date, Bangladesh (1987 revised / 2019 amended) rules:
     * Boishakh 1 falls on 14 April. Months 1-5 have 31 days, 6-11 have 30, and
     * Falgun (11) has 31 in a Gregorian leap year.
     */
    public static function bengaliDate(?CarbonInterface $date = null): string
    {
        $date = $date ?? Carbon::now();
        [$day, $month, $year] = self::toBengali($date);

        return self::digits($day).' '.self::BN_MONTHS[$month].' '.self::digits($year).' বঙ্গাব্দ';
    }

    /** @return array{0:int,1:int,2:int} [day, month, year] */
    public static function toBengali(CarbonInterface $date): array
    {
        $gYear = $date->year;

        // The Bengali year rolls over on 14 April.
        $newYear = Carbon::create($gYear, 4, 14)->startOfDay();
        $bYear = $date->copy()->startOfDay()->lt($newYear) ? $gYear - 594 : $gYear - 593;

        if ($date->copy()->startOfDay()->lt($newYear)) {
            $newYear = Carbon::create($gYear - 1, 4, 14)->startOfDay();
        }

        $elapsed = (int) $newYear->diffInDays($date->copy()->startOfDay());

        // Falgun gains a day when the *following* February had 29 days.
        $falgunLeap = Carbon::create($newYear->year + 1, 1, 1)->isLeapYear();
        $lengths = [31, 31, 31, 31, 31, 30, 30, 30, 30, 30, $falgunLeap ? 31 : 30, 30];

        $month = 1;
        foreach ($lengths as $i => $len) {
            if ($elapsed < $len) {
                $month = $i + 1;
                break;
            }
            $elapsed -= $len;
            $month = $i + 2;
        }

        return [$elapsed + 1, min($month, 12), $bYear];
    }

    /**
     * "৩৮ মিনিট আগে" / "২ ঘণ্টা আগে". Falls back to an absolute date past a
     * week, which is how Prothom Alo and mzamin handle older stories.
     */
    public static function ago(CarbonInterface $date): string
    {
        $now = Carbon::now();

        if ($date->gt($now)) {
            return self::date($date);
        }

        $seconds = $date->diffInSeconds($now);

        return match (true) {
            $seconds < 60 => 'এইমাত্র',
            $seconds < 3600 => self::digits((int) floor($seconds / 60)).' মিনিট আগে',
            $seconds < 86400 => self::digits((int) floor($seconds / 3600)).' ঘণ্টা আগে',
            $seconds < 604800 => self::digits((int) floor($seconds / 86400)).' দিন আগে',
            default => self::date($date),
        };
    }

    /** "১.২ হাজার" style counts for view/share numbers. */
    public static function compact(int $n): string
    {
        return match (true) {
            $n >= 10000000 => self::digits(round($n / 10000000, 1)).' কোটি',
            $n >= 100000 => self::digits(round($n / 100000, 1)).' লাখ',
            $n >= 1000 => self::digits(round($n / 1000, 1)).' হাজার',
            default => self::digits($n),
        };
    }

    /** Reading time from a body of Bangla text (~180 wpm for Bangla prose). */
    public static function readingTime(string $body): int
    {
        $words = str_word_count(strip_tags($body), 0, 'অআইঈউঊঋএঐওঔকখগঘঙচছজঝঞটঠডঢণতথদধনপফবভমযরলশষসহড়ঢ়য়ৎংঃঁািীুূৃেৈোৌ্০১২৩৪৫৬৭৮৯');

        return max(1, (int) ceil($words / 180));
    }
}
