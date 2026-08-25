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
        'verified_at', 'unsubscribed_at', 'ip',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'verified_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
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

    public function getRouteKeyName(): string
    {
        return 'token';
    }
}
