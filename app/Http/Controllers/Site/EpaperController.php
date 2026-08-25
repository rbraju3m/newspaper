<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Epaper;
use Illuminate\View\View;

class EpaperController extends Controller
{
    public function index(): View
    {
        $latest = Epaper::published()->latest('date')->first();

        return $latest
            ? $this->show($latest->date->toDateString())
            : view('site.epaper', ['epaper' => null, 'recent' => collect()]);
    }

    public function show(string $date): View
    {
        $epaper = Epaper::published()
            ->whereDate('date', $date)
            ->with('pages')
            ->firstOrFail();

        return view('site.epaper', [
            'epaper' => $epaper,
            'recent' => Epaper::published()
                ->latest('date')
                ->limit(14)
                ->get(['id', 'date', 'cover']),
        ]);
    }
}
