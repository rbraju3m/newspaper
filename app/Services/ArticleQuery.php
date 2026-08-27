<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Shared query shaping for every listing surface.
 *
 * Listing pages need exactly the columns the card renders and nothing more —
 * `body` is a longText and pulling it into a 20-row grid is the difference
 * between a 4ms and a 90ms query.
 */
class ArticleQuery
{
    public const CARD_COLUMNS = [
        'articles.id', 'articles.category_id', 'articles.author_id', 'articles.title',
        'articles.slug', 'articles.kicker', 'articles.excerpt', 'articles.type',
        'articles.image_id', 'articles.image', 'articles.video_url', 'articles.video_duration',
        'articles.is_premium', 'articles.is_lead', 'articles.published_at',
        'articles.views', 'articles.comments_count', 'articles.reading_time',
        'articles.locale',
    ];

    /**
     * What a card renders besides its own columns.
     *
     * `featuredImage` selects only the columns `Media::srcset()` and the
     * CLS-reserving width/height need; `conversions` is the JSON ladder.
     */
    public const CARD_RELATIONS = [
        'category:id,name,slug,path,color',
        'author:id,name,slug,avatar,designation',
        'featuredImage:id,disk,path,conversions,width,height',
    ];

    /** Base query for any public listing: published, card columns, eager loads. */
    public static function cards(): Builder
    {
        return self::deferred()->with(self::CARD_RELATIONS);
    }

    /**
     * The same query with the relations left off, for a caller assembling
     * several card lists at once.
     *
     * A listing page wants `cards()`: one list, three eager loads, done. The
     * front page is the other shape — a dozen independent lists on one
     * response — and there each `with()` is its own round trip, so the same
     * handful of sections, bylines and photographs get fetched a dozen times
     * over. Build the lists with this, then hand every article to
     * `hydrateCards()` once.
     *
     * Nothing may read a relation in between: strict mode is on outside
     * production, so a lazy load here is a 500 rather than a slow page.
     */
    public static function deferred(): Builder
    {
        return Article::query()
            ->select(self::CARD_COLUMNS)
            ->published();
    }

    /**
     * Load the card relations across any number of separately-built lists.
     *
     * Eloquent sets relations on the model instances themselves, so the lists
     * holding those instances see them without being passed back.
     *
     * @param  iterable<Article>  $articles
     */
    public static function hydrateCards(iterable $articles): void
    {
        (new EloquentCollection($articles))->load(self::CARD_RELATIONS);
    }

    public static function newest(int $limit = 10): Builder
    {
        return self::cards()->newest()->limit($limit);
    }

    /**
     * Related stories: editor-curated picks first, topped up automatically from
     * the same category. Falls back to the same section so the block is never
     * empty, which is what makes "related" useless on most news sites.
     */
    public static function related(Article $article, int $limit = 6): \Illuminate\Support\Collection
    {
        $curated = $article->relatedArticles()
            ->select(self::CARD_COLUMNS)
            ->published()
            ->with([
                'category:id,name,slug,path,color',
                'author:id,name,slug,avatar',
                'featuredImage:id,disk,path,conversions,width,height',
            ])
            ->get();

        if ($curated->count() >= $limit) {
            return $curated->take($limit);
        }

        $topicIds = $article->topics->pluck('id');

        $auto = self::cards()
            ->whereKeyNot($article->id)
            ->whereNotIn('articles.id', $curated->pluck('id'))
            // Same running-story cluster is a stronger signal than same section.
            ->when($topicIds->isNotEmpty(), fn (Builder $q) => $q->orderByRaw(
                'EXISTS (SELECT 1 FROM article_topic t WHERE t.article_id = articles.id
                         AND t.topic_id IN ('.$topicIds->map(fn () => '?')->implode(',').')) DESC',
                $topicIds->all(),
            ))
            ->where('category_id', $article->category_id)
            ->newest()
            ->limit($limit - $curated->count())
            ->get();

        return $curated->concat($auto);
    }
}
