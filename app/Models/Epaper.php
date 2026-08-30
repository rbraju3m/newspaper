<?php

namespace App\Models;

use App\Support\Bangla;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Epaper extends Model
{
    protected $fillable = ['date', 'edition', 'pdf', 'cover', 'pages_count', 'is_published'];

    protected function casts(): array
    {
        return ['date' => 'date', 'is_published' => 'boolean'];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(EpaperPage::class)->orderBy('page_number');
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    protected function banglaDate(): Attribute
    {
        return Attribute::get(fn (): string => Bangla::date($this->date));
    }

    /**
     * The house edition — the one whose URLs carry no `?edition=`.
     *
     * It is the first key of `site.epaper_editions` rather than a literal
     * `main`, so a paper whose primary edition is named something else still
     * gets the clean URLs. Changing that order changes what every canonical
     * e-paper URL is, and `EpaperController::show()` answers 301 on the old
     * one — so reorder it before anything is indexed, not after.
     */
    public static function defaultEdition(): string
    {
        return array_key_first(config('site.epaper_editions', [])) ?? 'main';
    }

    /**
     * Canonical URL: /epaper/{date}, plus `?edition=` for anything that is
     * not the house edition.
     *
     * One issue has exactly one address. A single-edition paper — which is
     * every install until somebody creates a second one — never sees the
     * query parameter at all, so no existing link changes shape.
     */
    protected function url(): Attribute
    {
        return Attribute::get(function (): string {
            $params = ['date' => $this->date->toDateString()];

            if ($this->edition !== self::defaultEdition()) {
                $params['edition'] = $this->edition;
            }

            return route('epaper.show', $params);
        });
    }

    /**
     * The Bangla name of this edition.
     *
     * The admin validates `edition` as any string up to 60 characters rather
     * than against the config list, so an edition with no configured label is
     * a legal row and falls back to its own key.
     */
    protected function editionLabel(): Attribute
    {
        return Attribute::get(
            fn (): string => config('site.epaper_editions')[$this->edition] ?? $this->edition
        );
    }
}
