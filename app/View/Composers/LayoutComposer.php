<?php

namespace App\View\Composers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Topic;
use App\Support\Locale;
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
                // `name_en` is selected even though the Bangla edition never
                // prints it: this payload is shared by both editions, and a
                // column left out of a *cached* model is a lazy load the
                // strict-mode guard cannot see — nothing fires `retrieved`
                // for something that came back from `unserialize()`.
                ->select(['id', 'parent_id', 'name', 'name_en', 'slug', 'path', 'color', 'position', 'show_in_nav', 'show_in_footer'])
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

    /**
     * Empty outside the default edition, on purpose.
     *
     * A topic has one name and `/topic/{slug}` has one edition, so every chip
     * in this rail is a Bangla name linking out of the English site. The two
     * templates that render it already guard on `isNotEmpty()`, so the rail
     * disappears rather than rendering a heading over nothing.
     *
     * This is the honest shape of the scope that was chosen: topics are not
     * part of the English edition. When they are, they need a `name_en` and
     * an `/en/topic` route, and this returns the same query for both.
     */
    private function trendingTopics(): Collection
    {
        if (! Locale::isDefault()) {
            return collect();
        }

        return $this->trending ??= Cache::remember(
            'layout.trending',
            now()->addMinutes(10),
            fn () => Topic::trending()->limit(8)->get(['id', 'name', 'slug', 'color']),
        );
    }

    private function breakingNews(): Collection
    {
        return $this->breaking ??= Cache::remember(self::key('layout.breaking'), now()->addSeconds(60), fn () => Article::query()
            ->select(['id', 'category_id', 'title', 'slug', 'locale'])
            ->with('category:id,path')
            ->locale(Locale::current())
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
    /**
     * The ticker is per edition; the category tree is not.
     *
     * The tree is the same rows in both — only which column of it a template
     * prints changes — so caching it twice would be two copies of a 106 KB
     * payload that can never disagree. The ticker is articles, and articles
     * belong to one edition.
     */
    private static function key(string $name, ?string $locale = null): string
    {
        $locale ??= Locale::current();

        return $locale === Locale::DEFAULT ? $name : $name.'.'.$locale;
    }

    public static function flush(): void
    {
        Cache::forget('layout.categories');
        Cache::forget('layout.trending');

        // Every edition's ticker, not just the one this request is in — an
        // editor pulling a breaking story down in the admin is running in
        // neither of them.
        foreach (Locale::ALL as $locale) {
            Cache::forget(self::key('layout.breaking', $locale));
        }

        app()->forgetInstance(self::class);
    }
}
