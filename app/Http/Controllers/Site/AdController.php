<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdController extends Controller
{
    /**
     * How many ids one beacon may carry.
     *
     * A page has three or four slots; ten is generous. The cap is what stops a
     * crafted request being a way to write to every row in the table at once.
     */
    private const MAX_PER_BEACON = 10;

    /** Click-through: count, then bounce to the advertiser. */
    public function click(Ad $ad): RedirectResponse
    {
        abort_unless($ad->url, 404);

        $ad->newQuery()->whereKey($ad->id)->increment('clicks');

        return redirect()->away($ad->url);
    }

    /**
     * Impressions, reported by the browser once a slot has actually been seen.
     *
     * **An impression is not a render.** Counting server-side while building
     * the page would have been one line and would have been wrong in both
     * directions: creatives are `loading="lazy"`, so a slot below the fold is
     * frequently never fetched at all — measuring the front page for CLS found
     * only one of its three slots had loaded by the time the run finished —
     * while every crawler that fetches the HTML would have been counted as a
     * reader. An advertiser paying per impression is paying for the second
     * number and would be billed the first.
     *
     * So the browser decides, on the IAB rule the rest of the industry uses:
     * half the creative in view for one continuous second. `ad-impressions.js`
     * applies it and posts the ids in one beacon per page.
     *
     * The cost is one query however many slots a page has — `whereIn` with a
     * single `increment` — which is what makes this affordable on a front page
     * that is otherwise eight queries warm.
     *
     * What it cannot do is prove the browser was honest. Anyone can post ids.
     * The rate limiter bounds it, `live()` means a retired creative cannot be
     * inflated, and the cap bounds one request; beyond that, client-reported
     * numbers are client-reported. A server-side render count would be just as
     * forgeable — by loading the page — and wrong on top of it.
     */
    public function impressions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:'.self::MAX_PER_BEACON],
            'ids.*' => ['integer'],
        ]);

        // `live()` rather than a bare `whereIn`: a paused or expired creative
        // is not earning impressions, and a stale tab left open for an hour
        // would otherwise keep reporting one.
        $counted = Ad::live()
            ->whereIn('id', array_unique($validated['ids']))
            ->increment('impressions');

        return response()->json(['counted' => $counted]);
    }
}
