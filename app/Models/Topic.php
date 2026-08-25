<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'image', 'color',
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

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
