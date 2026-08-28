<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ad extends Model
{
    protected $fillable = [
        'title', 'position', 'type', 'asset', 'media_id', 'html', 'url',
        'is_active', 'starts_at', 'ends_at', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** Active and inside its flight dates. */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderByDesc('priority');
    }

    /**
     * The media row the creative was uploaded as, when there is one.
     *
     * `asset` remains the source of truth for *where the file is* — it is what
     * an external creative or one imported before the media library existed
     * has — and this is what carries the derivative ladder.
     */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * srcset for the creative, or null when the media row has no ladder.
     *
     * **A single-rung srcset is emitted deliberately**, and that is worth
     * stating because the usual advice says not to. `ImageService` will not
     * upscale, so a 300×250 rectangle produces exactly one rung — and offering
     * it is still a clear win, because the rung is WebP and the `src` fallback
     * is the original: measured on the seeded sidebar creative, 6.5 KB against
     * 16.2 KB for the same pixels. The advice exists for the case where a
     * single candidate stops a browser reaching for something larger, and here
     * there is nothing larger to reach for.
     *
     * The guard is the same one `Article::imageSrcset` uses: lazy loading is
     * disabled outside production, so the relation is only read when a caller
     * eager-loaded it. `AdService` is the caller that does.
     */
    protected function creativeSrcset(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->relationLoaded('creative') || ! $this->creative) {
                return null;
            }

            return $this->creative->srcset() ?: null;
        });
    }

    protected function assetUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->asset
            ? (str_starts_with($this->asset, 'http') ? $this->asset : asset('storage/'.$this->asset))
            : null);
    }

    public function ctr(): float
    {
        return $this->impressions > 0
            ? round($this->clicks / $this->impressions * 100, 2)
            : 0.0;
    }
}
