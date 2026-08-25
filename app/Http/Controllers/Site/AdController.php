<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use Illuminate\Http\RedirectResponse;

class AdController extends Controller
{
    /** Click-through: count, then bounce to the advertiser. */
    public function click(Ad $ad): RedirectResponse
    {
        abort_unless($ad->url, 404);

        $ad->newQuery()->whereKey($ad->id)->increment('clicks');

        return redirect()->away($ad->url);
    }
}
