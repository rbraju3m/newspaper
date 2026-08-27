<?php

namespace App\View\Composers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Topic;
use App\Support\PackedCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Supplies the header, mega menu, search overlay and footer.
 *
 * These run on every single request, so the taxonomy is cached for an hour —
 * categories change a few times a year — while the breaking ticker gets a
 * 60-second window because it is the one part that has to be timely.
 *
 * It is bound **scoped** in `AppServiceProvider`, which is what makes the
 * per-request memos below correct. Four views are composed by this class, and
 * without a shared instance each of them re-read all three keys: twelve round
 * trips to the `cache` table on every request on the site, warm or cold, for
 * three distinct values. A scoped binding is also what keeps the memo from
 * outliving the request — a `static` would carry one test's category tree into
 * the next, which the cache store cannot do because `RefreshDatabase` truncates
 * it.
 */
class LayoutComposer
{
    private ?Collection $categories = null;

    private ?Collection $trending = null;

    private ?Collection $breaking = null;

    public function compose(View $view): void
    {
        $all = $this->categories();

        $view->with([
            'allCategories' => $all->whereNull('parent_id')->values(),
            'navCategories' => $all->whereNull('parent_id')->where('show_in_nav', true)->values(),
            'trendingTopics' => $this->trendingTopics(),
            'breakingNews' => $this->breakingNews(),
            // Deliberately not memoised: it is pure PHP over the tree above and
            // it is the one value here that varies with the route.
            'activeCategoryId' => $this->activeCategoryId($all),
        ]);
    }

    /** Full active tree in one query, assembled in PHP rather than N+1. */
    private function categories(): Collection
    {
        return $this->categories ??= PackedCache::remember('layout.categories', now()->addHour(), function () {
            $rows = Category::active()
                ->select(['id', 'parent_id', 'name', 'slug', 'path', 'color', 'position', 'show_in_nav', 'show_in_footer'])
                ->orderBy('position')
                ->get();

            $byParent = $rows->groupBy('parent_id');

            $rows->each(fn (Category $c) => $c->setRelation(
                'children',
                $byParent->get($c->id, collect())->values(),
            ));

            return $rows;
        });
    }

    private function trendingTopics(): Collection
    {
        return $this->trending ??= Cache::remember(
            'layout.trending',
            now()->addMinutes(10),
            fn () => Topic::trending()->limit(8)->get(['id', 'name', 'slug', 'color']),
        );
    }

    private function breakingNews(): Collection
    {
        return $this->breaking ??= Cache::remember('layout.breaking', now()->addSeconds(60), fn () => Article::query()
            ->select(['id', 'category_id', 'title', 'slug', 'locale'])
            ->with('category:id,path')
            ->breaking()
            ->newest()
            ->limit(6)
            ->get());
    }

    /**
     * Which nav item to highlight. Resolved from the route's category — and for
     * an article, from its section — so a sub-category page still lights up its
     * top-level parent in the bar.
     */
    private function activeCategoryId(Collection $all): ?int
    {
        $category = request()->route('category');

        if (is_string($category)) {
            $root = explode('/', $category)[0];

            return $all->firstWhere('slug', $root)?->id;
        }

        return null;
    }

    /**
     * Drop everything the header and footer are built from.
     *
     * Both halves matter: the cache keys, and the scoped instance holding this
     * request's memo of them. Forgetting only the keys would leave an editor
     * who just renamed a category looking at the old name for the rest of the
     * request that renamed it.
     */
    public static function flush(): void
    {
        Cache::forget('layout.categories');
        Cache::forget('layout.trending');
        Cache::forget('layout.breaking');

        app()->forgetInstance(self::class);
    }
}
