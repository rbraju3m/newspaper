<?php

namespace App\Http\Controllers\Site;

use App\Enums\ArticleType;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ArticleQuery;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoController extends Controller
{
    public function index(): View
    {
        $videos = ArticleQuery::cards()
            ->ofType(ArticleType::Video)
            ->newest()
            ->paginate(config('site.per_page'));

        return view('site.video-index', [
            'featured' => $videos->first(),
            'videos' => $videos,
        ]);
    }

    /**
     * A video **is** an article, so it belongs to an edition the way a story
     * does — not the way a gallery or an e-paper issue does.
     *
     * The id lookup is locale-blind, so a Bangla video can be requested at an
     * `/en` URL. It must not render there: `ArticleController` canonicalises
     * for exactly this reason and this route needed the same guard, or an
     * English frame would wrap a Bangla video at a URL claiming to be
     * English. Compared against `$request->url()` because neither form of
     * this route carries a query string.
     */
    public function show(Request $request, Article $article): Response
    {
        if ($article->type !== ArticleType::Video || ! $article->isVisible()) {
            throw new NotFoundHttpException;
        }

        $canonical = Locale::route('video.show', $article, $article->locale);

        if ($request->url() !== $canonical) {
            return redirect()->to($canonical, 301);
        }

        return response()->view('site.video-show', [
            'article' => $article->load(['category', 'author']),
            'playlist' => ArticleQuery::cards()
                ->ofType(ArticleType::Video)
                ->whereKeyNot($article->id)
                ->newest()
                ->limit(12)
                ->get(),
        ]);
    }
}
