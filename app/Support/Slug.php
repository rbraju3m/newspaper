<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Bangla-safe slugs, for URLs and for path segments.
 *
 * `Str::slug()` transliterates Bangla to ASCII — "রপ্তানি আয় বাড়াতে" becomes
 * "rptani-az-badate", which is lossy, unreadable and useless for Bangla search.
 * Bengali papers keep the native script, so we strip only what actually breaks
 * a path segment.
 *
 * `\p{M}` is the part that matters and the part that is easy to drop: Bangla
 * vowel signs and hasant are combining marks, not letters. Without it ক্রিকেট
 * collapses to করকট and distinct headlines collide.
 */
class Slug
{
    public static function make(string $text, int $max = 100): string
    {
        $base = Str::of($text)
            ->replaceMatches('/[^\p{L}\p{M}\p{N}\s-]+/u', ' ')
            ->squish()
            ->replace(' ', '-')
            ->lower()                     // no-op for Bangla, normalises Latin
            ->value();

        return self::trim($base, $max);
    }

    /** Cuts to length at a word boundary where one is close enough to the end. */
    private static function trim(string $slug, int $max): string
    {
        if (mb_strlen($slug) <= $max) {
            return trim($slug, '-');
        }

        $cut = mb_substr($slug, 0, $max);
        $lastHyphen = mb_strrpos($cut, '-');

        // Prefer a word boundary, but never trim away most of the slug.
        if ($lastHyphen !== false && $lastHyphen > $max * 0.6) {
            $cut = mb_substr($cut, 0, $lastHyphen);
        }

        return trim($cut, '-');
    }
}
