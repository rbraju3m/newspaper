<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GalleryImage extends Model
{
    public $timestamps = false;

    protected $fillable = ['gallery_id', 'media_id', 'path', 'caption', 'credit', 'position'];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => str_starts_with($this->path, 'http')
            ? $this->path
            : asset('storage/'.$this->path));
    }
}
