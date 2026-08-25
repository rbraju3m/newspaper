<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ArticleQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ArticleController extends Controller
{
    public function __invoke(Request $request, string $category, int $article, ?string $slug = null): Response
    {
        $article = Article::query()
            ->with([
                'category:id,name,slug,path,color',
                'author:id,name,slug,avatar,designation,bio',
                'tags:id,name,slug',
                'topics:id,name,slug,color',
                'gallery.images',
            ])
            ->withCount(['comments' => fn ($q) => $q->where('status', 'approved')])
            ->find($article);

        if (! $article || ! $this->isViewable($article, $request)) {
            throw new NotFoundHttpException;
        }

        // Canonicalise: a stale slug or wrong section still resolves, but
        // redirects once so search engines only ever index one URL.
        $canonical = $article->url;

        if ($request->url() !== $canonical) {
            return redirect()->to($canonical, 301);
        }

        $this->recordView($article, $request);

        return response()->view('site.article', [
            'article' => $article,
            'related' => ArticleQuery::related($article),
            'moreFromCategory' => ArticleQuery::cards()
                ->where('category_id', $article->category_id)
                ->whereKeyNot($article->id)
                ->newest()
                ->limit(5)
                ->get(),
        ]);
    }

    /** Staff may preview an unpublished story; nobody else may see it. */
    private function isViewable(Article $article, Request $request): bool
    {
        if ($article->isVisible()) {
            return true;
        }

        return $request->user()?->canAccessAdmin() ?? false;
    }

    /**
     * View counting. Deliberately not a queued job yet — a single UPDATE is
     * cheaper than a queue round-trip at this scale. The session guard stops a
     * refresh from inflating the number, which is what makes most-read useless
     * on sites that count every hit.
     */
    private function recordView(Article $article, Request $request): void
    {
        $seen = $request->session()->get('seen_articles', []);

        if (in_array($article->id, $seen, true)) {
            return;
        }

        $article->newQuery()->whereKey($article->id)->increment('views');

        // Keep the session small — only the last 200 matter for dedupe.
        $seen[] = $article->id;
        $request->session()->put('seen_articles', array_slice($seen, -200));
    }
}
