<?php

namespace App\Models;

use App\Enums\HomeBlockType;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeBlock extends Model
{
    protected $fillable = [
        'type', 'title', 'category_id', 'topic_id',
        'limit', 'position', 'is_active', 'column', 'options',
    ];

    protected function casts(): array
    {
        return [
            'type' => HomeBlockType::class,
            'is_active' => 'boolean',
            'options' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('position');
    }

    /** Heading shown above the block — the override, or the category name. */
    public function heading(): ?string
    {
        return $this->title ?: $this->category?->name ?: $this->topic?->name;
    }

    /** Blade partial that renders this block. */
    public function view(): string
    {
        return 'components.home.'.str_replace('_', '-', $this->type->value);
    }
}
