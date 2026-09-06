<?php

namespace App\Models;

use App\Support\Locale;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'name_en', 'slug'];

    protected static function booted(): void
    {
        static::saving(function (self $tag) {
            // Bangla tag names produce an empty Str::slug, so keep the letters.
            // \p{M} is essential: Bangla vowel signs and hasant are
            // combining marks, not letters. Without it ক্রিকেট becomes করকট.
            $tag->slug = $tag->slug ?: Str::of($tag->name)
                ->replaceMatches('/[^\p{L}\p{M}\p{N}\s-]+/u', '')
                ->squish()
                ->replace(' ', '-')
                ->lower()
                ->value();
        });
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    /** The tag's name in the edition being read; Bangla is the fallback. */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => Locale::isDefault()
            ? $this->name
            : ($this->name_en ?: $this->name));
    }

    /**
     * The tag's URL in the edition the reader is in.
     *
     * The **slug does not change between editions** — it is derived from the
     * Bangla name and stays Bangla, so `/en/tag/ক্রিকেট` is an English page at
     * a Bangla address. That is on purpose: a second slug column would mean a
     * tag has two identities and `Tag::articles()` would have to choose one,
     * and a percent-encoded path segment is something browsers and search
     * engines have handled correctly for twenty years. The Bangla site
     * already ships them everywhere.
     */
    public function url(): string
    {
        return Locale::route('tag.show', $this);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
