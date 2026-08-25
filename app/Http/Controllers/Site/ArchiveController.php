<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ArticleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ArchiveController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['nullable', 'string', 'exists:categories,slug'],
        ]);

        $date = isset($validated['date'])
            ? Carbon::parse($validated['date'])
            : Carbon::today();

        // Do not let the archive advertise days that cannot have content.
        if ($date->isFuture()) {
            $date = Carbon::today();
        }

        $category = isset($validated['category'])
            ? Category::where('slug', $validated['category'])->first()
            : null;

        $articles = ArticleQuery::cards()
            ->whereDate('published_at', $date)
            ->when($category, fn (Builder $q) => $q->inCategory($category))
            ->newest()
            ->paginate(config('site.per_page'))
            ->withQueryString();

        return view('site.archive', [
            'date' => $date,
            'category' => $category,
            'articles' => $articles,
            'categories' => Category::active()->roots()->orderBy('position')->get(['id', 'name', 'slug']),
        ]);
    }
}
