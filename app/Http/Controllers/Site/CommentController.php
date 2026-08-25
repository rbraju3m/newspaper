<?php

namespace App\Http\Controllers\Site;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\CommentRequest;
use App\Models\Article;
use App\Models\Comment;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class CommentController extends Controller
{
    public function store(CommentRequest $request, Article $article): RedirectResponse
    {
        if (! $article->allow_comments || ! $article->isVisible()) {
            return back()->withErrors(['body' => 'এই খবরে মন্তব্য করা যাবে না।']);
        }

        // Unverified readers can browse but not post — this is the single most
        // effective spam control, and cheaper than any filter.
        if (! $request->user()->hasVerifiedEmail()) {
            return back()->withErrors([
                'body' => 'মন্তব্য করতে প্রথমে আপনার ইমেইল যাচাই করুন।',
            ]);
        }

        $key = 'comment:'.$request->user()->id;

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'body' => 'একটু ধীরে! '.\App\Support\Bangla::digits($seconds).' সেকেন্ড পর আবার চেষ্টা করুন।',
            ]);
        }

        RateLimiter::hit($key, decaySeconds: 60);

        $requiresApproval = (bool) Setting::get('comments_require_approval', true);

        $comment = $article->comments()->create([
            'user_id' => $request->user()->id,
            'parent_id' => $request->validated('parent_id'),
            'body' => $request->validated('body'),
            'status' => $requiresApproval ? CommentStatus::Pending : CommentStatus::Approved,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        return back()
            ->with('status', $requiresApproval
                ? 'আপনার মন্তব্যটি পর্যালোচনার পর প্রকাশ করা হবে।'
                : 'আপনার মন্তব্য প্রকাশিত হয়েছে।')
            ->withFragment('comment-'.$comment->id);
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        Gate::authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $comment->update([
            'body' => $validated['body'],
            // An edit re-enters the queue; otherwise an approved comment could
            // be rewritten into anything after the fact.
            'status' => Setting::get('comments_require_approval', true)
                ? CommentStatus::Pending
                : $comment->status,
        ]);

        return back()->with('status', 'মন্তব্য সম্পাদিত হয়েছে।');
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $comment->delete();

        return back()->with('status', 'মন্তব্য মুছে ফেলা হয়েছে।');
    }

    public function like(Request $request, Comment $comment): JsonResponse
    {
        $result = $comment->likedBy()->toggle($request->user()->id);
        $liked = ! empty($result['attached']);

        $comment->newQuery()->whereKey($comment->id)
            ->{$liked ? 'increment' : 'decrement'}('likes_count');

        return response()->json([
            'liked' => $liked,
            'count' => $comment->fresh()->likes_count,
        ]);
    }

    public function report(Request $request, Comment $comment): JsonResponse
    {
        $reported = $request->session()->get('reported_comments', []);

        if (in_array($comment->id, $reported, true)) {
            return response()->json(['message' => 'আপনি ইতিমধ্যে এটি রিপোর্ট করেছেন।']);
        }

        $comment->increment('reports_count');

        // Auto-hide past a threshold so a brigading attack cannot keep abusive
        // content live while it waits in the queue.
        if ($comment->fresh()->reports_count >= 5 && $comment->status === CommentStatus::Approved) {
            $comment->update(['status' => CommentStatus::Pending]);
        }

        $reported[] = $comment->id;
        $request->session()->put('reported_comments', $reported);

        return response()->json(['message' => 'রিপোর্ট করার জন্য ধন্যবাদ।']);
    }
}
