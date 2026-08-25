<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\CommentController as SiteComments;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CommentController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('moderate', Comment::class);

        $status = $request->string('status', CommentStatus::Pending->value)->toString();

        return view('admin.comments.index', [
            'comments' => Comment::query()
                ->with(['user:id,name,email,avatar', 'article:id,title,slug,category_id,locale', 'article.category:id,path'])
                ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
                ->when($request->boolean('reported'), fn (Builder $q) => $q->where('reports_count', '>', 0))
                ->when($request->filled('q'), fn (Builder $q) => $q->where('body', 'like', '%'.$request->string('q').'%'))
                ->latest()
                ->paginate(30)
                ->withQueryString(),
            'status' => $status,
            'counts' => $this->counts(),
        ]);
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        Gate::authorize('moderate', Comment::class);

        $status = CommentStatus::tryFrom((string) $request->input('status'));
        abort_unless($status, 422);

        // moderated_by/moderated_at are intentionally not fillable — Comment is
        // created from user input, so its guard stays tight.
        $comment->forceFill([
            'status' => $status,
            'moderated_by' => $request->user()->id,
            'moderated_at' => now(),
        ])->save();

        return back()->with('status', 'মন্তব্যের অবস্থা: '.$status->label());
    }

    /** Bulk approve/reject/delete from the moderation queue. */
    public function bulk(Request $request): RedirectResponse
    {
        Gate::authorize('moderate', Comment::class);

        $validated = $request->validate([
            'action' => ['required', 'in:approve,reject,spam,delete'],
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $comments = Comment::whereIn('id', $validated['ids'])->get();

        foreach ($comments as $comment) {
            if ($validated['action'] === 'delete') {
                $comment->delete();

                continue;
            }

            // Saved one at a time, not mass-updated, so the counter hooks on
            // the model actually fire.
            $comment->forceFill([
                'status' => match ($validated['action']) {
                    'approve' => CommentStatus::Approved,
                    'reject' => CommentStatus::Rejected,
                    'spam' => CommentStatus::Spam,
                },
                'moderated_by' => $request->user()->id,
                'moderated_at' => now(),
            ])->save();
        }

        return back()->with('status', \App\Support\Bangla::digits($comments->count()).' টি মন্তব্যে পরিবর্তন করা হয়েছে।');
    }

    public function destroy(Comment $comment): RedirectResponse
    {
        Gate::authorize('moderate', Comment::class);

        $comment->delete();

        return back()->with('status', 'মন্তব্য মুছে ফেলা হয়েছে।');
    }

    private function counts(): array
    {
        $rows = Comment::query()->toBase()
            ->selectRaw('status, COUNT(*) AS total')
            ->whereNull('deleted_at')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($rows[CommentStatus::Pending->value] ?? 0),
            'approved' => (int) ($rows[CommentStatus::Approved->value] ?? 0),
            'rejected' => (int) ($rows[CommentStatus::Rejected->value] ?? 0),
            'spam' => (int) ($rows[CommentStatus::Spam->value] ?? 0),
            'reported' => Comment::where('reports_count', '>', 0)->count(),
        ];
    }
}
