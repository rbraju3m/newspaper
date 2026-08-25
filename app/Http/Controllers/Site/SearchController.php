<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ArticleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'exists:categories,slug'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', 'in:relevance,newest,popular'],
        ]);

        $term = trim($validated['q'] ?? '');
        $sort = $validated['sort'] ?? 'relevance';

        $articles = null;

        if ($term !== '') {
            $category = isset($validated['category'])
                ? Category::where('slug', $validated['category'])->first()
                : null;

            $articles = ArticleQuery::cards()
                ->search($term)
                ->when($category, fn (Builder $q) => $q->inCategory($category))
                ->when($validated['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('published_at', '>=', $d))
                ->when($validated['to'] ?? null, fn (Builder $q, $d) => $q->whereDate('published_at', '<=', $d))
                ->when($sort === 'newest', fn (Builder $q) => $q->newest())
                ->when($sort === 'popular', fn (Builder $q) => $q->orderByDesc('views'))
                // 'relevance' leaves MySQL's full-text ordering in place.
                ->paginate(config('site.per_page'))
                ->withQueryString();
        }

        return view('site.search', [
            'term' => $term,
            'articles' => $articles,
            'filters' => $validated,
            'categories' => Category::active()->roots()->orderBy('position')->get(['id', 'name', 'slug']),
        ]);
    }
}
