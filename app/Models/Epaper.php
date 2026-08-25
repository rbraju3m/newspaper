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
}
