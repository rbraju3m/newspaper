<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\LiveEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ApiController extends Controller
{
    /** Polled by the ticker so a breaking story appears without a reload. */
    public function breaking(): JsonResponse
    {
        $items = Cache::remember('api.breaking', now()->addSeconds(30), fn () => Article::query()
            ->select(['id', 'category_id', 'title', 'slug', 'locale'])
            ->with('category:id,path')
            ->breaking()
            ->newest()
            ->limit(6)
            ->get()
            ->map->tickerPayload()
            ->values());

        return response()->json(['items' => $items]);
    }

    /**
     * Live-blog polling. The client sends the newest entry id it already has,
     * so a quiet minute costs one indexed lookup and an empty array rather
     * than re-sending the whole timeline.
     */
    public function liveEntries(Request $request, Article $article): JsonResponse
    {
        abort_unless($article->isVisible(), 404);

        $since = $request->integer('since');

        $entries = LiveEntry::where('article_id', $article->id)
            ->when($since, fn ($q) => $q->where('id', '>', $since))
            ->with('author:id,name')
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get();

        return response()->json([
            'entries' => $entries->map->payload()->values(),
            'latest' => (int) LiveEntry::where('article_id', $article->id)->max('id'),
        ]);
    }

    /** Fired by sendBeacon from the share bar. */
    public function share(Request $request, Article $article): JsonResponse
    {
        $article->newQuery()->whereKey($article->id)->increment('shares');

        return response()->json(['ok' => true]);
    }
}
