<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PollOption extends Model
{
    public $timestamps = false;

    protected $fillable = ['poll_id', 'label', 'position'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /**
     * Share of the vote. The caller passes the total because options are
     * usually loaded via the poll's own relation, and reaching back through
     * ->poll would be a lazy load (disabled outside production).
     */
    public function percentage(?int $total = null): float
    {
        $total ??= $this->relationLoaded('poll') ? $this->poll->votes_count : 0;

        return $total > 0 ? round($this->votes_count / $total * 100, 1) : 0.0;
    }
}
