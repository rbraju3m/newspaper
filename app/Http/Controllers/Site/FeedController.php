<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Services\ArticleQuery;
use App\Support\Locale;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class FeedController extends Controller
{
    /**
     * Every cache key here is suffixed with the edition.
     *
     * `/rss` and `/en/rss` are the same method and the same cached shape, so
     * one key would serve whichever edition asked first to both — an English
     * feed of Bangla stories, for ten minutes, with nothing anywhere saying
     * so. The suffix is on the key rather than in a wrapper because these are
     * three different TTLs and there is nothing else to share.
     */
    private function key(string $name): string
    {
        return Locale::isDefault() ? $name : $name.'.'.Locale::current();
    }

    public function rss(): Response
    {
        $articles = Cache::remember(
            $this->key('feed.rss'),
            now()->addMinutes(10),
            fn () => ArticleQuery::newest(40)->get(),
        );

        return response()
            ->view('feeds.rss', compact('articles'))
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    public function sitemap(): Response
    {
        $data = Cache::remember($this->key('feed.sitemap'), now()->addHours(6), fn () => [
            'categories' => Category::active()->get(['path', 'updated_at']),
            'articles' => Article::published()
                ->locale(Locale::current())
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
     *
     * **Scoped to the edition like the others, but only registered on the
     * Bangla routes.** Google News wants a publication registered per edition
     * and the English one is a translation desk rather than a publication, so
     * there is nothing to submit it to yet. The scope is here anyway because
     * the alternative is a method that behaves differently from its two
     * neighbours for a reason that lives in a routes file.
     */
    public function newsSitemap(): Response
    {
        $articles = Cache::remember($this->key('feed.news-sitemap'), now()->addMinutes(5), fn () => Article::published()
            ->locale(Locale::current())
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
