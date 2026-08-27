<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NewsletterSubscriber extends Model
{
    protected $fillable = [
        'user_id', 'email', 'name', 'token', 'categories', 'frequency',
        'verified_at', 'unsubscribed_at', 'last_sent_at', 'ip',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'verified_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $s) => $s->token ??= Str::random(64));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Verified and not unsubscribed — the actual send list. */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNotNull('verified_at')->whereNull('unsubscribed_at');
    }

    /**
     * The send list for one edition.
     *
     * `last_sent_at` is the guard against a second cron run, or a hand re-run
     * after a partial failure, mailing the same digest twice in one morning.
     * It is compared against the *window* the edition covers rather than
     * against "today", so a weekly and a daily edition do not silence each
     * other.
     */
    #[Scope]
    protected function dueFor(Builder $query, string $frequency, \DateTimeInterface $since): void
    {
        $query->active()
            ->where('frequency', $frequency)
            ->where(fn (Builder $q) => $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<', $since));
    }

    /**
     * The categories this reader asked for, as ids — or an empty array meaning
     * "everything", which is what most of them are.
     *
     * The column is nullable *and* can hold an empty array, and those mean the
     * same thing. Collapsing both here keeps every caller from having to know.
     *
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return array_values(array_filter(array_map('intval', $this->categories ?? [])));
    }

    public function unsubscribeUrl(): string
    {
        return route('newsletter.unsubscribe', $this->token);
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
