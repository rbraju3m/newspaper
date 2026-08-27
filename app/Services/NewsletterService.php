<?php

namespace App\Services;

use App\Models\Article;
use App\Models\NewsletterSubscriber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides what one edition of the newsletter actually contains.
 *
 * Separate from the command that sends it because the interesting half is the
 * selection, not the loop: what counts as the day's news, what a reader who
 * asked for খেলা only should get instead, and — the part that matters most —
 * when there is nothing worth sending at all.
 *
 * **An empty edition is not sent.** A newsletter that arrives every morning
 * whether or not anything happened is how a newsletter gets filtered to spam,
 * and a quiet news day is a real thing. `edition()` returning an empty
 * collection is the signal to skip that reader entirely, not to mail them a
 * page of nothing.
 *
 * Editions are memoised per category signature. Most subscribers ask for
 * everything, so the general edition is built once and reused; only the
 * distinct *sets* of followed categories cost an extra query, rather than one
 * query per subscriber.
 */
class NewsletterService
{
    /** How far back each frequency looks, and how much it carries. */
    private const WINDOWS = [
        'daily' => ['hours' => 24, 'limit' => 8],
        'weekly' => ['hours' => 168, 'limit' => 14],
    ];

    /** @var array<string, Collection<int, Article>> */
    private array $memo = [];

    public static function frequencies(): array
    {
        return array_keys(self::WINDOWS);
    }

    public static function isFrequency(string $frequency): bool
    {
        return array_key_exists($frequency, self::WINDOWS);
    }

    /** The start of the window an edition of this frequency covers. */
    public function since(string $frequency): Carbon
    {
        return now()->subHours(self::WINDOWS[$frequency]['hours']);
    }

    /**
     * The stories one subscriber should receive, newest-first within rank.
     *
     * Ranking is editorial before algorithmic: what the desk marked as the
     * lead or a feature leads the email, and the rest is ordered by what
     * readers actually opened. A digest ordered purely by views is a digest
     * that leads on whatever went viral, which is not the same thing as
     * whatever mattered.
     *
     * @return Collection<int, Article>
     */
    public function editionFor(NewsletterSubscriber $subscriber, string $frequency): Collection
    {
        $categories = $subscriber->categoryIds();
        sort($categories);

        // Most readers ask for everything, so the general edition is built
        // once and handed to all of them.
        $key = $frequency.':'.implode(',', $categories);

        return $this->memo[$key] ??= $this->build($frequency, $categories);
    }

    /** @param  list<int>  $categories */
    private function build(string $frequency, array $categories): Collection
    {
        $window = self::WINDOWS[$frequency];

        return ArticleQuery::cards()
            ->where('published_at', '>=', $this->since($frequency))
            ->when($categories !== [], fn ($q) => $q->whereIn('articles.category_id', $categories))
            ->orderByRaw('(articles.is_lead OR articles.is_featured) DESC')
            ->orderByDesc('articles.views')
            ->orderByDesc('articles.published_at')
            ->limit($window['limit'])
            ->get();
    }

    /**
     * A Bangla subject line naming the edition.
     *
     * Dated, because an inbox holding thirty of these needs them to be
     * distinguishable at a glance, and because a subject that never changes is
     * a subject a mail client will thread into one conversation and hide.
     */
    public function subject(string $frequency, Collection $articles): string
    {
        $lead = $articles->first();

        $prefix = $frequency === 'weekly'
            ? 'সপ্তাহের খবর'
            : 'আজকের খবর';

        // The lead headline in the subject, because "আজকের খবর" alone tells a
        // reader nothing about whether to open it.
        return $lead
            ? $prefix.': '.str($lead->title)->limit(70)->value()
            : $prefix;
    }
}
