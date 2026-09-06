<?php

namespace App\Models;

use App\Support\Locale;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gallery extends Model
{
    use HasFactory;

    protected $table = 'galleries';

    protected $fillable = [
        'article_id', 'category_id', 'user_id', 'title', 'title_en', 'slug',
        'description', 'description_en', 'cover', 'status', 'published_at',
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
     * The gallery's title in the edition being read.
     *
     * Falls back to the Bangla title, because a gallery page needs a heading
     * and `photo-gallery-3` is not one — the same rule sections, topics and
     * print editions follow.
     */
    protected function displayTitle(): Attribute
    {
        return Attribute::get(fn (): string => Locale::isDefault()
            ? $this->title
            : ($this->title_en ?: $this->title));
    }

    /** The blurb, or null: not a heading, so it falls back to nothing. */
    protected function displayDescription(): Attribute
    {
        return Attribute::get(fn (): ?string => Locale::isDefault()
            ? $this->description
            : $this->description_en);
    }

    /**
     * The gallery's URL in the edition the reader is in.
     *
     * Follows the request, not a row — like an e-paper issue and unlike an
     * article. The photographs are the content and they are the same either
     * way; there is one gallery with one slug, shown in two frames.
     */
    public function url(): string
    {
        return Locale::route('photo.show', $this);
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
