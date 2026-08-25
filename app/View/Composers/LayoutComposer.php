<?php

namespace App\View\Composers;

use App\Models\Article;
use App\Models\Category;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Supplies the header, mega menu, search overlay and footer.
 *
 * These run on every single request, so the taxonomy is cached for an hour —
 * categories change a few times a year — while the breaking ticker gets a
 * 60-second window because it is the one part that has to be timely.
 */
class LayoutComposer
{
    public function compose(View $view): void
    {
        $all = $this->categories();

        $view->with([
            'allCategories' => $all->whereNull('parent_id')->values(),
            'navCategories' => $all->whereNull('parent_id')->where('show_in_nav', true)->values(),
            'trendingTopics' => $this->trendingTopics(),
            'breakingNews' => $this->breakingNews(),
            'activeCategoryId' => $this->activeCategoryId($all),
        ]);
    }

    /** Full active tree in one query, assembled in PHP rather than N+1. */
    private function categories(): Collection
    {
        return Cache::remember('layout.categories', now()->addHour(), function () {
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
        return Cache::remember(
            'layout.trending',
            now()->addMinutes(10),
            fn () => Topic::trending()->limit(8)->get(['id', 'name', 'slug', 'color']),
        );
    }

    private function breakingNews(): Collection
    {
        return Cache::remember('layout.breaking', now()->addSeconds(60), fn () => Article::query()
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
}
