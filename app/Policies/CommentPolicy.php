<?php

namespace App\Policies;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
    /**
     * Authors may edit their own comment for a short window; after that it is
     * part of the public record. Moderators are not bound by the window.
     */
    private const EDIT_WINDOW_MINUTES = 15;

    public function update(User $user, Comment $comment): bool
    {
        if ($user->role->canModerate()) {
            return true;
        }

        return $comment->user_id === $user->id
            && $comment->status !== CommentStatus::Rejected
            && $comment->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id || $user->role->canModerate();
    }

    public function moderate(User $user): bool
    {
        return $user->role->canModerate();
    }
}
