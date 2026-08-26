<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryImage extends Model
{
    public $timestamps = false;

    protected $fillable = ['gallery_id', 'media_id', 'path', 'caption', 'credit', 'position'];

    /**
     * `galleries.images_count` is denormalised, so it is maintained here the
     * way `Comment::booted()` maintains `comments_count`.
     *
     * Every reader currently goes through `withCount('images')`, which sets an
     * attribute of the same name and shadows the column — so a stale value is
     * invisible right up until somebody queries the column directly. That is
     * the trap worth closing rather than living with.
     *
     * The relation is stepped through with a query rather than
     * `$image->gallery`, which would be a lazy load.
     */
    protected static function booted(): void
    {
        static::created(function (self $image) {
            Gallery::whereKey($image->gallery_id)->increment('images_count');
        });

        static::updated(function (self $image) {
            if (! $image->wasChanged('gallery_id')) {
                return;
            }

            Gallery::whereKey($image->getRawOriginal('gallery_id'))
                ->where('images_count', '>', 0)
                ->decrement('images_count');

            Gallery::whereKey($image->gallery_id)->increment('images_count');
        });

        // Guarded, because the column is unsignedSmallInteger: decrementing a
        // drifted zero is an out-of-range *error* under strict mode, so it
        // would be a 500 rather than a wrong number.
        static::deleted(function (self $image) {
            Gallery::whereKey($image->gallery_id)
                ->where('images_count', '>', 0)
                ->decrement('images_count');
        });
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** srcset from the linked media, or null when there is no ladder. */
    protected function srcset(): Attribute
    {
        return Attribute::get(function (): ?string {
            if (! $this->relationLoaded('media') || ! $this->media) {
                return null;
            }

            return $this->media->srcset() ?: null;
        });
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => str_starts_with($this->path, 'http')
            ? $this->path
            : asset('storage/'.$this->path));
    }
}
