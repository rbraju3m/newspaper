<?php

namespace App\Models;

use App\Support\Locale;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'name_en', 'slug', 'description', 'description_en', 'image', 'color',
        'is_active', 'is_trending', 'position',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_trending' => 'boolean'];
    }

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    #[Scope]
    protected function trending(Builder $query): void
    {
        $query->where('is_active', true)
            ->where('is_trending', true)
            ->orderBy('position');
    }

    /**
     * The topic's name in the edition being read, falling back to the Bangla
     * one rather than to the slug — a running-story heading that reads
     * "world-cup-2026" is worse than one that reads "বিশ্বকাপ ২০২৬".
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): string => Locale::isDefault()
            ? $this->name
            : ($this->name_en ?: $this->name));
    }

    /**
     * The blurb, in the edition being read — and **null rather than the
     * Bangla one** when there is no English version.
     *
     * This is the one place the fallback goes the other way, and it is
     * deliberate. A name has to render something or the page has no heading;
     * a description is optional everywhere it appears, so an English page
     * showing nothing is better than one showing a Bangla paragraph under an
     * English heading.
     */
    protected function displayDescription(): Attribute
    {
        return Attribute::get(fn (): ?string => Locale::isDefault()
            ? $this->description
            : $this->description_en);
    }

    /** The topic's URL in the edition the reader is in. */
    public function url(): string
    {
        return Locale::route('topic.show', $this);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
