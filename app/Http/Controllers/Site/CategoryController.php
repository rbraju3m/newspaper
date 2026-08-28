<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ArticleQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategoryController extends Controller
{
    public function __invoke(Request $request, string $category): Response
    {
        // Resolved manually rather than by implicit binding: the materialised
        // path contains slashes, which route-model binding cannot express.
        //
        // `children` is eager-loaded because the view below renders it. Strict
        // mode would not have caught reading it lazily — the guard is not set
        // on a model that came back from a single-row query (see CLAUDE.md) —
        // so this was one silent extra query on every category page.
        $category = Category::active()->with('children')->where('path', $category)->first()
            ?? throw new NotFoundHttpException;

        $query = ArticleQuery::cards()->inCategory($category)->newest();

        // The lead story gets its own treatment, so pull it out of the grid.
        $lead = (clone $query)->first();

        $articles = $query
            ->when($lead, fn ($q) => $q->whereKeyNot($lead->id))
            ->paginate(config('site.per_page'))
            ->withQueryString();

        // Infinite scroll asks for the next page as an HTML fragment so the
        // markup stays identical to the server-rendered first page.
        if ($request->ajax()) {
            return response()->json([
                'html' => view('site.partials.article-grid-items', compact('articles'))->render(),
                'next' => $articles->nextPageUrl(),
            ]);
        }

        return response()->view('site.category', [
            'category' => $category,
            'lead' => $lead,
            'articles' => $articles,
            'children' => $category->children,
            'ancestors' => $category->ancestors(),
        ]);
    }
}
