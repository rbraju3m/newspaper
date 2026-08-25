<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    protected $table = 'media';

    protected $fillable = [
        'user_id', 'disk', 'path', 'filename', 'mime', 'size',
        'width', 'height', 'conversions', 'alt', 'caption', 'credit',
    ];

    protected function casts(): array
    {
        return ['conversions' => 'array'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => Storage::disk($this->disk)->url($this->path));
    }

    /** Named derivative ("card", "hero", "thumb"), falling back to the original. */
    public function conversion(string $name): string
    {
        $path = data_get($this->conversions, $name);

        return $path
            ? Storage::disk($this->disk)->url($path)
            : $this->url;
    }

    /** srcset string for responsive <img>, built from the stored widths. */
    public function srcset(): string
    {
        return collect($this->conversions ?? [])
            ->filter(fn ($p, $k) => is_string($p) && preg_match('/^w(\d+)$/', $k))
            ->map(fn ($p, $k) => Storage::disk($this->disk)->url($p).' '.substr($k, 1).'w')
            ->implode(', ');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
