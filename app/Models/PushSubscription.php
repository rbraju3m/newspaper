<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser install that has agreed to receive notifications.
 *
 * Identity is the `endpoint`, not the user: the same reader on a phone and a
 * laptop is two subscriptions, and a reader who signs out and back in is still
 * the same one. `user_id` is a label the browser acquires when somebody signs
 * in on it, which is what makes the account preferences screen able to speak
 * for a subscription it did not create.
 */
class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'endpoint', 'public_key', 'auth_token',
        'content_encoding', 'user_agent', 'breaking',
    ];

    protected function casts(): array
    {
        return [
            'breaking' => 'boolean',
            'last_success_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Everything a breaking-news alert should reach. */
    #[Scope]
    protected function forBreaking(Builder $query): void
    {
        $query->where('breaking', true);
    }

    /**
     * The shape `minishlink/web-push` wants.
     *
     * Kept here rather than in the service because it is the model's own
     * columns being renamed — `p256dh` and `auth` are the names the browser
     * uses, and the table deliberately does not.
     */
    public function toWebPush(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'publicKey' => $this->public_key,
            'authToken' => $this->auth_token,
            'contentEncoding' => $this->content_encoding,
        ];
    }
}
