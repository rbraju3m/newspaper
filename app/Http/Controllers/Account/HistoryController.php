<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ReadingHistory;
use App\Services\ArticleQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoryController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.history', [
            'articles' => $request->user()->readArticles()
                ->select(ArticleQuery::CARD_COLUMNS)
                ->with(['category:id,name,slug,path,color', 'author:id,name,slug,avatar'])
                ->paginate(18),
        ]);
    }

    /**
     * Called by the reading-progress bar via sendBeacon. One row per
     * user/article, updated on revisit — and progress only ever moves forward,
     * so scrolling back up does not erase how far they got.
     */
    public function track(Request $request, Article $article): JsonResponse
    {
        $validated = $request->validate([
            'progress' => ['required', 'integer', 'min:0', 'max:100'],
            'seconds' => ['nullable', 'integer', 'min:0', 'max:36000'],
        ]);

        $row = ReadingHistory::firstOrNew([
            'user_id' => $request->user()->id,
            'article_id' => $article->id,
        ]);

        $row->fill([
            'progress' => max($row->progress ?? 0, $validated['progress']),
            'seconds' => ($row->seconds ?? 0) + ($validated['seconds'] ?? 0),
            'read_at' => now(),
        ])->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Article $article): RedirectResponse
    {
        $request->user()->readingHistory()->where('article_id', $article->id)->delete();

        return back()->with('status', 'ইতিহাস থেকে সরানো হয়েছে।');
    }

    public function clear(Request $request): RedirectResponse
    {
        $request->user()->readingHistory()->delete();

        return back()->with('status', 'পড়ার ইতিহাস মুছে ফেলা হয়েছে।');
    }
}
