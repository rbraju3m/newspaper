<?php

namespace App\Models;

use App\Support\Locale;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpaperPage extends Model
{
    public $timestamps = false;

    protected $fillable = ['epaper_id', 'page_number', 'image', 'thumbnail', 'section', 'section_en', 'pdf'];

    /**
     * The printed section's caption in the edition being read, or null.
     *
     * Falls back to nothing rather than to the Bangla caption: the page
     * number beside it is the heading and always renders, so an English
     * reader gets "Page 6" where a Bangla one gets "খেলা — পৃষ্ঠা ৬". Same
     * rule as a job title, and the opposite of a name.
     */
    protected function displaySection(): Attribute
    {
        return Attribute::get(fn (): ?string => Locale::isDefault()
            ? $this->section
            : $this->section_en);
    }

    /**
     * `epapers.pages_count` is denormalised, so it is maintained here the way
     * `GalleryImage::booted()` maintains `galleries.images_count`.
     *
     * Nothing reads the column today — the admin uses `withCount('pages')` —
     * but a counter that is never written is a counter that is wrong the first
     * time somebody trusts it.
     *
     * The relation is stepped through with a query rather than
     * `$page->epaper`, which would be a lazy load.
     */
    protected static function booted(): void
    {
        static::created(function (self $page) {
            Epaper::whereKey($page->epaper_id)->increment('pages_count');
        });

        // Guarded, because the column is unsignedTinyInteger: decrementing a
        // drifted zero is an out-of-range *error* under strict mode, so it
        // would be a 500 rather than a wrong number.
        static::deleted(function (self $page) {
            Epaper::whereKey($page->epaper_id)
                ->where('pages_count', '>', 0)
                ->decrement('pages_count');
        });
    }

    public function epaper(): BelongsTo
    {
        return $this->belongsTo(Epaper::class);
    }
}
