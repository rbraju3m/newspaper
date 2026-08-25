<?php

namespace App\Http\Controllers\Site;

use App\Enums\ArticleType;
use App\Http\Controllers\Controller;
use App\Services\ArticleQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ListingController extends Controller
{
    public function latest(Request $request): Response
    {
        return $this->render($request, ArticleQuery::cards()->newest()->paginate(config('site.per_page')), [
            'title' => 'সর্বশেষ খবর',
            'description' => 'সর্বশেষ প্রকাশিত সব খবর এক জায়গায়।',
        ]);
    }

    public function popular(Request $request): Response
    {
        $days = (int) $request->integer('days', 7);
        $days = in_array($days, [1, 7, 30], true) ? $days : 7;

        return $this->render($request, ArticleQuery::cards()->mostRead($days)->paginate(config('site.per_page')), [
            'title' => 'সর্বাধিক পঠিত',
            'description' => 'পাঠকের সবচেয়ে বেশি পড়া খবর।',
            'days' => $days,
        ]);
    }

    public function opinion(Request $request): Response
    {
        return $this->render($request, ArticleQuery::cards()
            ->ofType(ArticleType::Opinion)
            ->newest()
            ->paginate(config('site.per_page')), [
                'title' => 'মতামত',
                'description' => 'সম্পাদকীয়, উপসম্পাদকীয় ও কলাম।',
                'view' => 'site.opinion',
            ]);
    }

    /** Shared render path: JSON fragment for infinite scroll, else the page. */
    private function render(Request $request, LengthAwarePaginator $articles, array $meta): Response
    {
        $articles->withQueryString();

        if ($request->ajax()) {
            return response()->json([
                'html' => view('site.partials.article-list-items', compact('articles'))->render(),
                'next' => $articles->nextPageUrl(),
            ]);
        }

        return response()->view($meta['view'] ?? 'site.listing', $meta + compact('articles'));
    }
}
