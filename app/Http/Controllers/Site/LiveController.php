<?php

namespace App\Http\Controllers\Site;

use App\Enums\ArticleType;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\ArticleQuery;
use Illuminate\View\View;

class LiveController extends Controller
{
    /** Live TV stream plus any running live blogs — the DBC News pattern. */
    public function __invoke(): View
    {
        return view('site.live', [
            'streamUrl' => Setting::get('live_stream_url'),
            'liveBlogs' => ArticleQuery::cards()
                ->ofType(ArticleType::Live)
                ->newest()
                ->limit(10)
                ->get(),
        ]);
    }
}
