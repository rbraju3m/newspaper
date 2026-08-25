<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ArticleStatus;
use App\Enums\CommentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('admin.dashboard', [
            'counts' => $this->counts(),
            'needsAttention' => $this->needsAttention(),
            'topStories' => Article::published()
                ->where('published_at', '>=', now()->subDays(7))
                ->orderByDesc('views')
                ->limit(8)
                ->get(['id', 'category_id', 'title', 'slug', 'locale', 'views', 'comments_count']),
            'recent' => Article::query()
                ->with(['author:id,name', 'category:id,name,color'])
                // Reporters see their own desk, editors see the whole queue.
                ->when(! $user->role->canPublish(), fn ($q) => $q->where('author_id', $user->id))
                ->latest('updated_at')
                ->limit(10)
                ->get(),
            'chart' => $this->publishingTrend(),
        ]);
    }

    private function counts(): array
    {
        // One grouped query rather than five COUNT(*) round trips.
        $byStatus = Article::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'published' => (int) ($byStatus[ArticleStatus::Published->value] ?? 0),
            'draft' => (int) ($byStatus[ArticleStatus::Draft->value] ?? 0),
            'review' => (int) ($byStatus[ArticleStatus::Review->value] ?? 0),
            'scheduled' => (int) ($byStatus[ArticleStatus::Scheduled->value] ?? 0),
            'readers' => User::where('role', UserRole::Reader)->count(),
            'views_today' => (int) Article::whereDate('published_at', today())->sum('views'),
        ];
    }

    /** The desk's to-do list: things actively waiting on a human. */
    private function needsAttention(): array
    {
        return [
            'pending_comments' => Comment::where('status', CommentStatus::Pending)->count(),
            'reported_comments' => Comment::where('reports_count', '>=', 3)
                ->where('status', '!=', CommentStatus::Rejected)
                ->count(),
            'in_review' => Article::where('status', ArticleStatus::Review)->count(),
            'scheduled_due' => Article::where('status', ArticleStatus::Scheduled)
                ->where('published_at', '<=', now())
                ->count(),
        ];
    }

    /** Articles published per day for the last fortnight. */
    private function publishingTrend(): array
    {
        $rows = Article::query()
            ->toBase()
            ->selectRaw('DATE(published_at) AS d, COUNT(*) AS total')
            ->where('status', ArticleStatus::Published->value)
            ->whereNull('deleted_at')
            ->where('published_at', '>=', now()->subDays(13)->startOfDay())
            ->groupBy('d')
            ->pluck('total', 'd');

        // Fill the gaps so the chart has no holes on quiet days.
        return collect(range(13, 0))
            ->mapWithKeys(function (int $daysAgo) use ($rows) {
                $date = now()->subDays($daysAgo)->toDateString();

                return [$date => (int) ($rows[$date] ?? 0)];
            })
            ->all();
    }
}
