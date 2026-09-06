<?php

namespace Database\Seeders\Support;

/**
 * Generates plausible English demo copy, the mirror of `BanglaContent`.
 *
 * **Deterministic on a seed**, unlike its Bangla counterpart, and that is the
 * difference that matters. `BanglaContent` is called while rows are being
 * created and any text will do. This is called to produce the *translation of
 * a specific story*, so re-running `articles:translate` after the table has
 * been rebuilt has to yield the same English article for the same Bangla one
 * — otherwise a screenshot, a bookmark or a comparison between two boxes
 * stops meaning anything. Every method takes the seed the caller derives from
 * the article.
 *
 * It is **not a translator** and does not pretend to be. The English text has
 * nothing to do with the Bangla text beyond the section it sits in. That is
 * the honest shape for demo content: a machine translation of filler is
 * filler that reads as though somebody checked it.
 */
class EnglishContent
{
    private const SUBJECTS = [
        'The Chief Adviser', 'The Finance Adviser', 'The Election Commission',
        'The World Bank', 'Bangladesh Bank', 'The Home Ministry', 'The High Court',
        'The Anti-Corruption Commission', 'Dhaka University', 'The BCB',
        'The Foreign Ministry', 'The Met Office', 'The city corporation',
        'Bangladesh Railway', 'The Power Division', 'The education board',
        'The United Nations', 'The IMF',
    ];

    private const PREDICATES = [
        'issues fresh guidance', 'takes a major decision', 'opens an inquiry',
        'publishes its report', 'sounds a warning', 'approves the plan',
        'raises the budget allocation', 'extends the deadline',
        'suspends operations', 'signs an agreement', 'launches a drive',
        'revises the policy',
    ];

    private const CONTEXTS = [
        'as pressure builds', 'after a week of talks', 'amid rising costs',
        'ahead of the deadline', 'following the review', 'as the season opens',
        'after months of delay', 'with the season under way',
    ];

    private const SENTENCE_WORDS = [
        'policy', 'committee', 'process', 'review', 'situation', 'decision',
        'analysis', 'initiative', 'assessment', 'framework', 'consultation',
        'allocation', 'oversight', 'guidance', 'statement', 'proposal',
        'deadline', 'authority', 'district', 'ministry', 'official', 'response',
    ];

    private const DATELINES = ['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Sylhet', 'Barishal'];

    /**
     * A small deterministic PRNG, seeded per article.
     *
     * `mt_srand()` is deliberately not used: it is global state, and seeding
     * it inside a loop over 40 articles would leave the process's randomness
     * pinned for whatever ran next — including the factories, in a test.
     */
    private static function pick(array $list, int $seed, int $salt): string
    {
        return $list[abs(crc32($seed.':'.$salt)) % count($list)];
    }

    public static function headline(int $seed): string
    {
        return self::pick(self::SUBJECTS, $seed, 1)
            .' '.self::pick(self::PREDICATES, $seed, 2)
            .' '.self::pick(self::CONTEXTS, $seed, 3);
    }

    public static function sentence(int $seed, int $salt, int $words = 12): string
    {
        $out = [];

        for ($i = 0; $i < $words; $i++) {
            $out[] = self::pick(self::SENTENCE_WORDS, $seed, $salt * 100 + $i);
        }

        return ucfirst(implode(' ', $out)).'.';
    }

    public static function excerpt(int $seed): string
    {
        return self::sentence($seed, 7, 18);
    }

    /**
     * A body in the same shape a real one has — paragraphs with a subhead in
     * the middle — because that is what `.prose-editorial` is styled for and
     * a wall of `<p>` would not exercise it.
     *
     * The markup is inside `Html::ALLOWED`, so it survives the `saving()`
     * sanitiser unchanged rather than being silently stripped on write.
     */
    public static function body(int $seed, int $paragraphs = 8): string
    {
        $out = [];

        for ($i = 0; $i < $paragraphs; $i++) {
            if ($i === 3) {
                $out[] = '<h2>'.rtrim(self::sentence($seed, 50 + $i, 6), '.').'</h2>';
            }

            $out[] = '<p>'.trim(
                self::sentence($seed, 10 + $i, 14).' '.self::sentence($seed, 30 + $i, 11)
            ).'</p>';
        }

        return implode("\n", $out);
    }

    public static function dateline(int $seed): string
    {
        return self::pick(self::DATELINES, $seed, 9);
    }
}
