<?php

namespace App\Policies;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\User;

class ArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isStaff();
    }

    /** Reporters only see their own drafts; editors see everything. */
    public function view(User $user, Article $article): bool
    {
        return $user->role->canPublish() || $article->author_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->role->isStaff();
    }

    /**
     * A reporter may keep editing their own story until it is published —
     * after that it is the desk's copy, not theirs.
     */
    public function update(User $user, Article $article): bool
    {
        if ($user->role->canPublish()) {
            return true;
        }

        return $article->author_id === $user->id
            && $article->status !== ArticleStatus::Published;
    }

    public function publish(User $user): bool
    {
        return $user->role->canPublish();
    }

    public function delete(User $user, Article $article): bool
    {
        if ($user->role->canManageSite()) {
            return true;
        }

        // Editors may bin anything unpublished; reporters only their own drafts.
        if ($user->role->canPublish()) {
            return $article->status !== ArticleStatus::Published;
        }

        return $article->author_id === $user->id
            && $article->status === ArticleStatus::Draft;
    }

    /** Homepage placement flags are an editorial decision, not a writer's. */
    public function feature(User $user): bool
    {
        return $user->role->canPublish();
    }
}
