<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Services\ArticleQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookmarkController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.bookmarks', [
            'articles' => $request->user()->bookmarks()
                ->select(ArticleQuery::CARD_COLUMNS)
                ->with(['category:id,name,slug,path,color', 'author:id,name,slug,avatar'])
                ->paginate(18),
        ]);
    }

    /**
     * Toggle. Answers 401 rather than redirecting so the Alpine store can roll
     * back its optimistic flip and send the reader to login.
     */
    public function toggle(Request $request, Article $article): JsonResponse
    {
        if (! $request->user()) {
            return response()->json(['message' => 'লগইন প্রয়োজন।'], 401);
        }

        $result = $request->user()->bookmarks()->toggle($article->id);
        $bookmarked = ! empty($result['attached']);

        return response()->json([
            'bookmarked' => $bookmarked,
            'message' => $bookmarked ? 'খবরটি সংরক্ষিত হয়েছে।' : 'সংরক্ষণ থেকে সরানো হয়েছে।',
        ]);
    }

    public function destroy(Request $request, Article $article): JsonResponse
    {
        $request->user()->bookmarks()->detach($article->id);

        return response()->json(['bookmarked' => false]);
    }
}
