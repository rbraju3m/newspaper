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

    /** One page of live-blog updates. */
    private const LIVE_PAGE = 30;

    /**
     * Live-blog polling. The client sends the newest entry id it already has,
     * so a quiet minute costs one indexed lookup and an empty array rather
     * than re-sending the whole timeline.
     *
     * The two branches want opposite ends of the timeline. A first load wants
     * the top of it — pinned above newest — and does not care what is below
     * the fold. An incremental poll wants the *oldest* updates above the
     * cursor, because the cursor may only advance as far as what was actually
     * sent: a burst larger than one page has to drain over successive polls
     * rather than be stepped over. Both come back newest-first, which is the
     * order the client prepends in.
     */
    public function liveEntries(Request $request, Article $article): JsonResponse
    {
        abort_unless($article->isVisible(), 404);

        $since = $request->integer('since');

        $query = LiveEntry::where('article_id', $article->id)->with('author:id,name');

        if ($since) {
            $entries = $query->where('id', '>', $since)
                ->orderBy('id')
                ->limit(self::LIVE_PAGE)
                ->get()
                ->reverse()
                ->values();

            $latest = (int) ($entries->max('id') ?: $since);
        } else {
            $entries = $query->orderByDesc('is_pinned')
                ->orderByDesc('published_at')
                ->limit(self::LIVE_PAGE)
                ->get();

            $latest = (int) LiveEntry::where('article_id', $article->id)->max('id');
        }

        return response()->json([
            'entries' => $entries->map->payload()->values(),
            'latest' => $latest,
        ]);
    }

    /** Fired by sendBeacon from the share bar. */
    public function share(Request $request, Article $article): JsonResponse
    {
        $article->newQuery()->whereKey($article->id)->increment('shares');

        return response()->json(['ok' => true]);
    }
}
