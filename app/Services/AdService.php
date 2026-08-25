<?php

namespace App\Services;

use App\Models\Ad;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves which creative fills each slot.
 *
 * Registered as a singleton so a page with six ad slots issues one query, not
 * six — and the result is cached, because ad rotation does not need to be
 * per-request accurate.
 */
class AdService
{
    private ?Collection $live = null;

    public function all(): Collection
    {
        return $this->live ??= Cache::remember(
            'ads.live',
            now()->addMinutes(5),
            fn () => Ad::live()->get()->groupBy('position'),
        );
    }

    /** Highest-priority live ad for a slot, or null to render the empty box. */
    public function for(string $position): ?Ad
    {
        $candidates = $this->all()->get($position);

        if (! $candidates || $candidates->isEmpty()) {
            return null;
        }

        // Rotate among equal-priority creatives so one does not hog the slot.
        $top = $candidates->first()->priority;

        return $candidates->where('priority', $top)->random();
    }

    public static function flush(): void
    {
        Cache::forget('ads.live');
    }
}
