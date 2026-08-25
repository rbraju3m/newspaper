<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Services\ArticleQuery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class FeedController extends Controller
{
    public function rss(): Response
    {
        $articles = Cache::remember(
            'feed.rss',
            now()->addMinutes(10),
            fn () => ArticleQuery::newest(40)->get(),
        );

        return response()
            ->view('feeds.rss', compact('articles'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $data = Cache::remember('feed.sitemap', now()->addHours(6), fn () => [
            'categories' => Category::active()->get(['path', 'updated_at']),
            'articles' => Article::published()
                ->newest()
                ->limit(5000)
                ->with('category:id,path')
                ->get(['id', 'category_id', 'slug', 'locale', 'updated_at', 'published_at']),
        ]);

        return response()
            ->view('feeds.sitemap', $data)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /**
     * Google News sitemap — only the last 48 hours, which is the window Google
     * News accepts. None of the reference sites publish one correctly.
     */
    public function newsSitemap(): Response
    {
        $articles = Cache::remember('feed.news-sitemap', now()->addMinutes(5), fn () => Article::published()
            ->where('published_at', '>=', now()->subHours(48))
            ->with('category:id,path')
            ->newest()
            ->limit(1000)
            ->get(['id', 'category_id', 'title', 'slug', 'locale', 'published_at']));

        return response()
            ->view('feeds.news-sitemap', compact('articles'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
