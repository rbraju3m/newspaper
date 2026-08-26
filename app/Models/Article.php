<?php

namespace App\Models;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Support\Bangla;
use App\Support\Html;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Article extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id', 'author_id', 'editor_id', 'title', 'slug', 'kicker',
        'subtitle', 'excerpt', 'body', 'type', 'status', 'image_id', 'image',
        'image_caption', 'image_credit', 'video_url', 'video_duration',
        'is_lead', 'is_featured', 'is_breaking', 'is_premium', 'is_pinned',
        'allow_comments', 'breaking_until', 'published_at', 'locale',
        'translation_of', 'meta_title', 'meta_description', 'dateline', 'source',
    ];

    protected function casts(): array
    {
        return [
            'type' => ArticleType::class,
            'status' => ArticleStatus::class,
            'is_lead' => 'boolean',
            'is_featured' => 'boolean',
            'is_breaking' => 'boolean',
            'is_premium' => 'boolean',
            'is_pinned' => 'boolean',
            'allow_comments' => 'boolean',
            'published_at' => 'datetime',
            'breaking_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $article) {
            $article->slug = $article->slug ?: static::uniqueSlug($article->title, $article->locale, $article->id);

            // The body is rendered with {!! !!}, so it is sanitised on the way
            // in rather than on the way out — once per save instead of once per
            // reader, and the invariant holds for every writer, not only the
            // article form. Guarded by isDirty() so an unchanged body is not
            // reparsed, and so a partially-selected model (ArticleQuery::cards()
            // omits `body`) is not asked for an attribute it never loaded.
            //
            // Reading time is derived from the body, so it belongs in the same
            // guard: recomputing it needs the attribute too, and reaching for
            // `body` on a model that never selected it is exactly the strict-mode
            // throw this avoids.
            if ($article->isDirty('body')) {
                $article->body = Html::sanitize($article->body);
                $article->reading_time = Bangla::readingTime((string) $article->body);
            }

            // Publishing without an explicit date means "now".
            if ($article->status === ArticleStatus::Published && ! $article->published_at) {
                $article->published_at = now();
            }
        });
    }

    /**
     * Build a URL slug that keeps the Bangla headline intact.
     *
     * Str::slug() transliterates Bangla to ASCII, turning
     * "রপ্তানি আয় বাড়াতে" into "rptani-az-badate" — lossy, unreadable, and
     * worthless for Bangla search queries. Bengali papers keep the native
     * script in the URL, so we do the same and only strip what actually breaks
     * a path segment.
     *
     * \p{M} is essential: Bangla vowel signs and hasant are combining marks,
     * not letters. Without it ক্রিকেট collapses to করকট.
     */
    private static function uniqueSlug(string $title, ?string $locale, ?int $ignoreId = null): string
    {
        $base = Slug::make($title, 100) ?: 'সংবাদ';

        $slug = $base;
        $i = 1;

        while (static::withTrashed()
            ->where('slug', $slug)
            ->where('locale', $locale ?? 'bn')
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.++$i;
        }

        return $slug;
    }

    /**
     * Cap the slug length without leaving a half-word on the end. Measured in
     * characters, not bytes — a Bangla character is three UTF-8 bytes, and the
     * unique index has room for it.
     */
    // ── Relationships ────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'image_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class);
    }

    public function gallery(): HasOne
    {
        return $this->hasOne(Gallery::class);
    }

    /** Live-blog timeline: pinned entries first, then newest. */
    public function liveEntries(): HasMany
    {
        return $this->hasMany(LiveEntry::class)
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Approved top-level comments, newest first — what the article page renders. */
    public function threadedComments(): HasMany
    {
        return $this->comments()
            ->whereNull('parent_id')
            ->where('status', 'approved')
            ->with(['user', 'replies' => fn ($q) => $q->where('status', 'approved')->with('user')])
            ->latest();
    }

    /** Editor-curated related stories; the controller falls back to automatic. */
    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'article_related', 'article_id', 'related_id')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function bookmarkedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'bookmarks');
    }

    public function translation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'translation_of');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published)
            ->where('published_at', '<=', now());
    }

    #[Scope]
    protected function locale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }

    #[Scope]
    protected function newest(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    #[Scope]
    protected function ofType(Builder $query, ArticleType|string $type): void
    {
        $query->where('type', $type instanceof ArticleType ? $type->value : $type);
    }

    /** Includes cross-posted stories and everything in child categories. */
    #[Scope]
    protected function inCategory(Builder $query, Category $category): void
    {
        $ids = $category->descendantIds();

        $query->where(function (Builder $q) use ($ids) {
            $q->whereIn('category_id', $ids)
                ->orWhereHas('categories', fn (Builder $c) => $c->whereIn('categories.id', $ids));
        });
    }

    #[Scope]
    protected function breaking(Builder $query): void
    {
        $query->published()
            ->where('is_breaking', true)
            ->where(fn (Builder $q) => $q->whereNull('breaking_until')->orWhere('breaking_until', '>', now()));
    }

    #[Scope]
    protected function mostRead(Builder $query, int $days = 7): void
    {
        // Callers reach this through ArticleQuery::cards(), which has already
        // applied published(); re-applying it only duplicates predicates.
        $query->where('published_at', '>=', now()->subDays($days))
            ->orderByDesc('views');
    }

    /**
     * Full-text search with a LIKE fallback. MySQL will not index tokens
     * shorter than innodb_ft_min_token_size (3 by default), so short Bangla
     * queries would otherwise return nothing.
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        if (mb_strlen($term) < 3 || $query->getConnection()->getDriverName() !== 'mysql') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $like)
                ->orWhere('excerpt', 'like', $like));

            return;
        }

        $query->whereFullText(['title', 'excerpt', 'body'], $term, ['mode' => 'boolean']);
    }

    // ── Accessors ────────────────────────────────────────────────────────

    /**
     * Canonical URL: /{category-path}/{id}/{slug}. The id makes the URL stable
     * when a headline is edited, which is how every reference site does it.
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => route('article.show', [
            'category' => $this->category?->path ?? 'news',
            'article' => $this->id,
            'slug' => $this->slug,
        ]));
    }

    /**
     * srcset for the lead image, or null when there is nothing to offer.
     *
     * Only the linked Media carries derivatives. The denormalised `image`
     * column is a bare path — often an external URL or a legacy import — so it
     * gets a plain `src` and no srcset. Returning null rather than a
     * single-candidate srcset matters: a srcset listing one width tells the
     * browser that is the only option, which is worse than omitting it.
     */
    protected function imageSrcset(): Attribute
    {
        return Attribute::get(function (): ?string {
            // Lazy loading is disabled outside production, so never touch the
            // relation unless a listing eager-loaded it. ArticleQuery::cards()
            // does; an ad-hoc query may not.
            if (! $this->relationLoaded('featuredImage') || ! $this->featuredImage) {
                return null;
            }

            return $this->featuredImage->srcset() ?: null;
        });
    }

    /**
     * Intrinsic dimensions of the lead image, for reserving layout space.
     *
     * @return array{0:?int,1:?int}
     */
    protected function imageDimensions(): Attribute
    {
        return Attribute::get(function (): array {
            if (! $this->relationLoaded('featuredImage') || ! $this->featuredImage) {
                return [null, null];
            }

            return [$this->featuredImage->width, $this->featuredImage->height];
        });
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->image) {
                return null;
            }

            return str_starts_with($this->image, 'http')
                ? $this->image
                : asset('storage/'.$this->image);
        });
    }

    protected function publishedAgo(): Attribute
    {
        return Attribute::get(fn (): string => $this->published_at
            ? Bangla::ago($this->published_at)
            : '');
    }

    protected function isNew(): Attribute
    {
        return Attribute::get(fn (): bool => $this->published_at?->gt(now()->subHour()) ?? false);
    }

    // ── Behaviour ────────────────────────────────────────────────────────

    /** Payload for the breaking-news ticker JSON. */
    public function tickerPayload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
        ];
    }

    public function isVisible(): bool
    {
        return $this->status === ArticleStatus::Published
            && $this->published_at?->lte(now());
    }

    public function scheduleFor(Carbon $when): void
    {
        $this->forceFill([
            'status' => ArticleStatus::Scheduled,
            'published_at' => $when,
        ])->save();
    }
}
