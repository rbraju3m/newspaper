<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'email', 'phone', 'password', 'role', 'status',
        'avatar', 'designation', 'bio', 'social', 'preferences',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'social' => 'array',
            'preferences' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Authors need a stable public slug for /author/{slug}.
        static::creating(function (self $user) {
            $user->slug ??= static::uniqueSlug($user->name);
        });
    }

    private static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'user';
        $slug = $base;
        $i = 1;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_id');
    }

    public function editedArticles(): HasMany
    {
        return $this->hasMany(Article::class, 'editor_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function bookmarks(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'bookmarks')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'desc');
    }

    public function readingHistory(): HasMany
    {
        return $this->hasMany(ReadingHistory::class);
    }

    public function readArticles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'reading_history')
            ->withPivot(['progress', 'seconds', 'read_at'])
            ->orderByPivot('read_at', 'desc');
    }

    public function reactions(): BelongsToMany
    {
        return $this->belongsToMany(Article::class, 'reactions')->withPivot('type');
    }

    // ── Authorisation ────────────────────────────────────────────────────

    public function canAccessAdmin(): bool
    {
        return $this->isActive() && $this->role->isStaff();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    // ── Accessors ────────────────────────────────────────────────────────

    /** Falls back to a generated initials avatar so the UI never shows a gap. */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->avatar) {
                return str_starts_with($this->avatar, 'http')
                    ? $this->avatar
                    : asset('storage/'.$this->avatar);
            }

            return 'https://ui-avatars.com/api/?name='.urlencode($this->name)
                .'&background=C8102E&color=fff&bold=true';
        });
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn (): string => Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($p) => Str::substr($p, 0, 1))
            ->implode(''));
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    #[Scope]
    protected function staff(Builder $query): void
    {
        $query->whereIn('role', ['admin', 'editor', 'reporter']);
    }

    #[Scope]
    protected function authors(Builder $query): void
    {
        $query->staff()->where('articles_count', '>', 0);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
