<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

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

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
