<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id', 'name', 'name_en', 'slug', 'path', 'description', 'color',
        'icon', 'layout_type', 'position', 'is_active', 'show_in_nav',
        'show_in_footer', 'meta_title', 'meta_description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_nav' => 'boolean',
            'show_in_footer' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $category) {
            $category->slug ??= Str::slug($category->name_en ?: $category->name);
            $category->path = $category->buildPath();
        });

        // A parent's path change has to cascade, or children become unreachable.
        static::saved(function (self $category) {
            if ($category->wasChanged('path')) {
                $category->children->each->save();
            }
        });
    }

    /** Materialised path: "khela" or "khela/cricket". */
    private function buildPath(): string
    {
        $parent = $this->parent_id
            ? static::find($this->parent_id)
            : null;

        return $parent ? $parent->path.'/'.$this->slug : $this->slug;
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** Direct children plus their children — two levels is all the nav shows. */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    /** Includes cross-posted stories, which `articles()` alone would miss. */
    public function allArticles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    public function homeBlocks(): HasMany
    {
        return $this->hasMany(HomeBlock::class);
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function roots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    #[Scope]
    protected function inNav(Builder $query): void
    {
        $query->active()->where('show_in_nav', true)->orderBy('position');
    }

    #[Scope]
    protected function inFooter(Builder $query): void
    {
        $query->active()->where('show_in_footer', true)->orderBy('position');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Self + all descendant ids, for "everything under খেলা" queries. */
    public function descendantIds(): array
    {
        return once(fn () => static::query()
            ->where('path', 'like', $this->path.'/%')
            ->orWhere('id', $this->id)
            ->pluck('id')
            ->all());
    }

    public function ancestors(): \Illuminate\Support\Collection
    {
        $slugs = explode('/', $this->path);
        array_pop($slugs);

        if (empty($slugs)) {
            return collect();
        }

        // Rebuild each ancestor path so they come back in tree order.
        $paths = [];
        $current = '';
        foreach ($slugs as $slug) {
            $current = $current ? $current.'/'.$slug : $slug;
            $paths[] = $current;
        }

        return static::whereIn('path', $paths)
            ->get()
            ->sortBy(fn ($c) => array_search($c->path, $paths, true))
            ->values();
    }

    public function getRouteKeyName(): string
    {
        return 'path';
    }

    public function url(): string
    {
        return route('category.show', $this->path);
    }
}
