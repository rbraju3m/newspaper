<?php

namespace Tests\Unit;

use App\Support\Bangla;
use App\Support\Fmt;
use App\Support\Locale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * `Fmt` — the locale switch the `@bn*` directives call.
 *
 * The directives kept their names on purpose: they appear a few hundred times
 * across 115 templates and renaming them would be a diff over nearly every
 * view for no behaviour. `Bangla` is unchanged and still has its own tests;
 * this covers the switch, and the three English answers that are **not**
 * translations of the Bangla ones.
 */
class FmtTest extends TestCase
{
    private function inEnglish(callable $fn): mixed
    {
        App::setLocale(Locale::ALTERNATE);

        try {
            return $fn();
        } finally {
            App::setLocale(Locale::DEFAULT);
        }
    }

    public function test_the_bangla_side_is_unchanged(): void
    {
        $date = Carbon::parse('2026-08-25 21:45:00');

        $this->assertSame(Bangla::digits(12345), Fmt::digits(12345));
        $this->assertSame(Bangla::date($date), Fmt::date($date));
        $this->assertSame(Bangla::time($date), Fmt::time($date));
        $this->assertSame(Bangla::compact(12400), Fmt::compact(12400));
        $this->assertSame(Bangla::fullDate($date), Fmt::fullDate($date));
    }

    public function test_english_numerals_are_grouped(): void
    {
        $this->assertSame('12,345', $this->inEnglish(fn () => Fmt::digits(12345)));
        $this->assertSame('7', $this->inEnglish(fn () => Fmt::digits(7)));
    }

    public function test_english_dates_are_english(): void
    {
        $date = Carbon::parse('2026-08-25 21:45:00');

        $this->assertSame('25 August 2026', $this->inEnglish(fn () => Fmt::date($date)));
        $this->assertSame('Tuesday, 25 August 2026', $this->inEnglish(fn () => Fmt::fullDate($date)));
    }

    /**
     * Bangla names the part of the day — `রাত ৯:৪৫` — and has no AM/PM.
     * "night 9:45" is not English, so this is a different answer rather than
     * a translated one.
     */
    public function test_english_time_uses_am_pm_rather_than_a_part_of_the_day(): void
    {
        $date = Carbon::parse('2026-08-25 21:45:00');

        $this->assertSame('9:45 PM', $this->inEnglish(fn () => Fmt::time($date)));
    }

    /**
     * Bangla counts in লাখ (10⁵) and কোটি (10⁷); English in K (10³) and M
     * (10⁶). Translating the words while keeping the Bangla thresholds would
     * print "0.1M" where a reader expects "100K".
     */
    public function test_english_compact_counts_use_english_scales(): void
    {
        $this->assertSame('999', $this->inEnglish(fn () => Fmt::compact(999)));
        $this->assertSame('12.4K', $this->inEnglish(fn () => Fmt::compact(12400)));
        $this->assertSame('100K', $this->inEnglish(fn () => Fmt::compact(100000)));
        $this->assertSame('2.5M', $this->inEnglish(fn () => Fmt::compact(2500000)));

        // The same numbers on the Bangla side, to show the scales really differ.
        $this->assertSame('১ লাখ', Fmt::compact(100000));
    }

    public function test_english_relative_times_agree_with_english_grammar(): void
    {
        $this->inEnglish(function () {
            $this->assertSame('just now', Fmt::ago(Carbon::now()->subSeconds(5)));
            $this->assertSame('1 minute ago', Fmt::ago(Carbon::now()->subMinutes(1)));
            $this->assertSame('38 minutes ago', Fmt::ago(Carbon::now()->subMinutes(38)));
            $this->assertSame('1 hour ago', Fmt::ago(Carbon::now()->subHours(1)));
            $this->assertSame('3 days ago', Fmt::ago(Carbon::now()->subDays(3)));
        });
    }

    /** Past a week both editions fall back to an absolute date. */
    public function test_an_old_story_gets_a_date_rather_than_a_relative_time(): void
    {
        $old = Carbon::now()->subDays(30);

        $this->assertSame(Fmt::date($old), Fmt::ago($old));
        $this->inEnglish(fn () => $this->assertSame(Fmt::date($old), Fmt::ago($old)));
    }
}
