<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['article_id', 'user_id', 'parent_id', 'body', 'status', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
            'moderated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Keep the denormalised counters honest without a scheduled job.
        // Relations are stepped through with queries rather than properties:
        // reading $comment->parent would be a lazy load, which is disabled.
        static::created(function (self $comment) {
            if ($comment->status === CommentStatus::Approved) {
                $comment->article()->increment('comments_count');
            }

            if ($comment->parent_id) {
                static::whereKey($comment->parent_id)->increment('replies_count');
            }
        });

        static::updated(function (self $comment) {
            if (! $comment->wasChanged('status')) {
                return;
            }

            // getOriginal() applies casts and hands back a CommentStatus, so
            // comparing it to ->value never matches. getRawOriginal() returns
            // the stored string, which is what we want here.
            $wasApproved = $comment->getRawOriginal('status') === CommentStatus::Approved->value;
            $isApproved = $comment->status === CommentStatus::Approved;

            if ($isApproved && ! $wasApproved) {
                $comment->article()->increment('comments_count');
            } elseif (! $isApproved && $wasApproved) {
                $comment->article()->decrement('comments_count');
            }
        });

        static::deleted(function (self $comment) {
            if ($comment->status === CommentStatus::Approved) {
                $comment->article()->decrement('comments_count');
            }

            if ($comment->parent_id) {
                static::whereKey($comment->parent_id)->decrement('replies_count');
            }
        });
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    /**
     * `comment_likes` has `created_at` (stamped by the database with
     * useCurrent()) and no `updated_at` — the same shape as `bookmarks`.
     * withTimestamps() would write both columns and every like would fail with
     * "Unknown column 'updated_at'", so the pivot is declared the way the
     * bookmarks relation declares its own.
     */
    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_likes')
            ->withPivot('created_at');
    }

    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where('status', CommentStatus::Approved);
    }

    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', CommentStatus::Pending);
    }
}
