<?php

namespace App\Services;

use App\Enums\ArticleType;
use App\Enums\HomeBlockType;
use App\Models\Article;
use App\Models\Category;
use App\Models\Gallery;
use App\Models\HomeBlock;
use App\Models\Poll;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the front page from the editor-configured block list.
 *
 * The whole page is assembled once and cached for a short window. A news
 * homepage is the single most-hit URL on the site and its content only changes
 * when something is published, so serving it from cache is the difference
 * between surviving a traffic spike and not.
 */
class HomepageService
{
    public const CACHE_KEY = 'homepage.blocks';

    public const CACHE_TTL = 120; // seconds

    /** @return array{main: Collection, sidebar: Collection} */
    public function build(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $blocks = HomeBlock::active()->with(['category', 'topic'])->get();

            // Stories already placed higher up the page, so a section block does
            // not repeat what the hero just showed.
            $used = collect();

            $resolved = $blocks->map(function (HomeBlock $block) use ($used) {
                $data = $this->resolve($block, $used);
                $used->push(...$this->placedArticleIds($block, $data));

                return ['block' => $block, 'data' => $data];
            });

            return [
                'main' => $resolved->where('block.column', 'main')->values(),
                'sidebar' => $resolved->where('block.column', 'sidebar')->values(),
            ];
        });
    }

    /**
     * Article ids this block just put on the page, so later blocks can skip
     * them. Ranked lists (most-read, latest) are deliberately excluded: they
     * promise a true ranking, and silently omitting a story that appeared
     * higher up would make the list wrong.
     */
    private function placedArticleIds(HomeBlock $block, mixed $data): array
    {
        return match ($block->type) {
            HomeBlockType::Hero,
            HomeBlockType::CategoryGrid,
            HomeBlockType::CategoryList,
            HomeBlockType::Carousel,
            HomeBlockType::VideoRow,
            HomeBlockType::OpinionRow => $data instanceof Collection ? $data->pluck('id')->all() : [],

            // Nested shape: a collection of {category, articles}.
            HomeBlockType::ThreeColumn => $data instanceof Collection
                ? $data->flatMap(fn ($c) => $c['articles']->pluck('id'))->all()
                : [],

            HomeBlockType::TopicCluster => is_array($data)
                ? $data['articles']->pluck('id')->all()
                : [],

            default => [],
        };
    }

    private function resolve(HomeBlock $block, Collection $used): mixed
    {
        return match ($block->type) {
            HomeBlockType::Hero => $this->hero($block),
            HomeBlockType::CategoryGrid,
            HomeBlockType::CategoryList,
            HomeBlockType::Carousel => $this->forCategory($block, $used),
            HomeBlockType::ThreeColumn => $this->threeColumn($block, $used),
            HomeBlockType::VideoRow => $this->byType($block, ArticleType::Video, $used),
            HomeBlockType::OpinionRow => $this->byType($block, ArticleType::Opinion, $used),
            HomeBlockType::PhotoRow => $this->galleries($block),
            HomeBlockType::MostRead => $this->mostRead($block),
            HomeBlockType::Latest => $this->latest($block),
            HomeBlockType::TopicCluster => $this->topicCluster($block),
            HomeBlockType::Poll => $this->poll(),
            HomeBlockType::Ad, HomeBlockType::Newsletter => null,
        };
    }

    /**
     * Hero: pinned and flagged leads first, then the newest stories to fill.
     * Editors flag a lead; if they have not, the page still looks deliberate.
     */
    private function hero(HomeBlock $block): Collection
    {
        $leads = ArticleQuery::cards()
            ->where(fn (Builder $q) => $q->where('is_lead', true)->orWhere('is_pinned', true))
            ->orderByDesc('is_pinned')
            ->newest()
            ->limit($block->limit)
            ->get();

        if ($leads->count() >= $block->limit) {
            return $leads;
        }

        $fill = ArticleQuery::cards()
            ->whereNotIn('articles.id', $leads->pluck('id'))
            ->newest()
            ->limit($block->limit - $leads->count())
            ->get();

        return $leads->concat($fill);
    }

    private function forCategory(HomeBlock $block, Collection $used): Collection
    {
        if (! $block->category) {
            return collect();
        }

        return ArticleQuery::cards()
            ->inCategory($block->category)
            ->whereNotIn('articles.id', $used->all() ?: [0])
            ->newest()
            ->limit($block->limit)
            ->get();
    }

    /**
     * Three sections side by side. Which three is configurable per block;
     * defaults to the trio that reads best on a Bangladeshi front page.
     */
    private function threeColumn(HomeBlock $block, Collection $used): Collection
    {
        $slugs = $block->options['categories'] ?? ['international', 'economy', 'technology'];

        return Category::whereIn('slug', $slugs)
            ->get()
            ->map(fn (Category $category) => [
                'category' => $category,
                'articles' => ArticleQuery::cards()
                    ->inCategory($category)
                    ->whereNotIn('articles.id', $used->all() ?: [0])
                    ->newest()
                    ->limit($block->limit)
                    ->get(),
            ])
            ->filter(fn ($c) => $c['articles']->isNotEmpty())
            ->values();
    }

    private function byType(HomeBlock $block, ArticleType $type, Collection $used): Collection
    {
        return ArticleQuery::cards()
            ->ofType($type)
            ->whereNotIn('articles.id', $used->all() ?: [0])
            ->newest()
            ->limit($block->limit)
            ->get();
    }

    private function galleries(HomeBlock $block): Collection
    {
        return Gallery::published()
            ->withCount('images')
            ->latest('published_at')
            ->limit($block->limit)
            ->get();
    }

    private function mostRead(HomeBlock $block): Collection
    {
        return ArticleQuery::cards()
            ->mostRead(days: 7)
            ->limit($block->limit)
            ->get();
    }

    private function latest(HomeBlock $block): Collection
    {
        return ArticleQuery::cards()
            ->newest()
            ->limit($block->limit)
            ->get();
    }

    private function topicCluster(HomeBlock $block): ?array
    {
        $topic = $block->topic
            ?? \App\Models\Topic::trending()->withCount('articles')->first();

        if (! $topic) {
            return null;
        }

        return [
            'topic' => $topic,
            'articles' => ArticleQuery::cards()
                ->whereHas('topics', fn (Builder $q) => $q->whereKey($topic->id))
                ->newest()
                ->limit($block->limit)
                ->get(),
        ];
    }

    private function poll(): ?Poll
    {
        return Poll::open()->with('options')->latest()->first();
    }

    /** Called whenever an article is published or the layout changes. */
    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
