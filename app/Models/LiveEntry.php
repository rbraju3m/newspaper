<?php

namespace App\Models;

use App\Support\Bangla;
use App\Support\Html;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEntry extends Model
{
    protected $fillable = [
        'article_id', 'user_id', 'headline', 'body',
        'image', 'embed_url', 'is_pinned', 'is_key', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'is_key' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            // Sanitised on write, like Article::body. This one has two readers:
            // the timeline prints it with {!! !!}, and payload() hands it to the
            // polling client, which injects it with x-html — innerHTML, where a
            // <script> is inert but `<img onerror>` is not.
            if ($entry->isDirty('body')) {
                $entry->body = Html::sanitize($entry->body);
            }
        });
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function timeLabel(): Attribute
    {
        return Attribute::get(fn (): string => Bangla::time($this->published_at));
    }

    /** Shape sent to the polling endpoint. */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'headline' => $this->headline,
            'body' => $this->body,
            'image' => $this->image ? asset('storage/'.$this->image) : null,
            'time' => Bangla::time($this->published_at),
            'ago' => Bangla::ago($this->published_at),
            'pinned' => $this->is_pinned,
            'key' => $this->is_key,
            'author' => $this->author?->name,
            'at' => $this->published_at->toIso8601String(),
        ];
    }
}
