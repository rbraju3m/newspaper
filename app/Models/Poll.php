<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Poll extends Model
{
    protected $fillable = ['question', 'slug', 'is_active', 'multiple', 'closes_at'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'multiple' => 'boolean',
            'closes_at' => 'datetime',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    #[Scope]
    protected function open(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()));
    }

    public function isClosed(): bool
    {
        return ! $this->is_active || ($this->closes_at && $this->closes_at->isPast());
    }

    /** Whether this identity has already voted — guests keyed by fingerprint. */
    public function hasVoted(?User $user, ?string $fingerprint): bool
    {
        return $this->votes()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when(! $user && $fingerprint, fn ($q) => $q->where('fingerprint', $fingerprint))
            ->when(! $user && ! $fingerprint, fn ($q) => $q->whereRaw('1 = 0'))
            ->exists();
    }
}
