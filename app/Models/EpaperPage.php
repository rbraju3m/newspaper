<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpaperPage extends Model
{
    public $timestamps = false;

    protected $fillable = ['epaper_id', 'page_number', 'image', 'thumbnail', 'section', 'pdf'];

    public function epaper(): BelongsTo
    {
        return $this->belongsTo(Epaper::class);
    }
}
