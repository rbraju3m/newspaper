<?php

namespace App\View\Composers;

use App\Enums\CommentStatus;
use App\Models\Comment;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/** Supplies the badge counts shown in the admin sidebar. */
class AdminComposer
{
    public function compose(View $view): void
    {
        $view->with('pendingComments', Cache::remember(
            'admin.pending_comments',
            now()->addSeconds(30),
            fn () => Comment::where('status', CommentStatus::Pending)->count(),
        ));
    }
}
