<?php

namespace App\Models;

use App\Support\Html;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'slug', 'body', 'is_active', 'meta_title', 'meta_description'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $page) {
            // `site/page.blade.php` prints this with {!! !!}. Pages are
            // admin-only, but so is the allow-list, and one of them is cheaper
            // to trust than the other.
            if ($page->isDirty('body')) {
                $page->body = Html::sanitize($page->body);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
