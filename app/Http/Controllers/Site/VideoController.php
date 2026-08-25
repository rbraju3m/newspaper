<?php

namespace App\Http\Controllers\Site;

use App\Enums\ArticleType;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ArticleQuery;
use Illuminate\View\View;
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

    public function show(Article $article): View
    {
        if ($article->type !== ArticleType::Video || ! $article->isVisible()) {
            throw new NotFoundHttpException;
        }

        return view('site.video-show', [
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
