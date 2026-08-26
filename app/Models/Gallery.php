<?php

namespace App\Models;

use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gallery extends Model
{
    use HasFactory;

    protected $table = 'galleries';

    protected $fillable = [
        'article_id', 'category_id', 'user_id', 'title', 'slug',
        'description', 'cover', 'status', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $gallery) {
            $gallery->slug = $gallery->slug ?: static::uniqueSlug($gallery->title, $gallery->id);
        });
    }

    /**
     * A Bangla-safe slug, made unique by suffix.
     *
     * `galleries.slug` is the route key, so a collision is a 404 on somebody
     * else's gallery rather than a validation error — the same shape as
     * `Article::uniqueSlug()`, and unique across the whole table because
     * galleries are not scoped by locale.
     */
    private static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Slug::make($title, 100) ?: 'ফটো-গ্যালারি';

        $slug = $base;
        $i = 1;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    public function images(): HasMany
    {
        return $this->hasMany(GalleryImage::class)->orderBy('position');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', 'published')->where('published_at', '<=', now());
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
