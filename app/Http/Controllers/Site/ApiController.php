<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
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

    /** Fired by sendBeacon from the share bar. */
    public function share(Request $request, Article $article): JsonResponse
    {
        $article->newQuery()->whereKey($article->id)->increment('shares');

        return response()->json(['ok' => true]);
    }
}
