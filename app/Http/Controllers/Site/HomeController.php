<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\HomepageService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(HomepageService $homepage): View
    {
        ['main' => $main, 'sidebar' => $sidebar] = $homepage->build();

        return view('site.home', [
            'mainBlocks' => $main,
            'sidebarBlocks' => $sidebar,
        ]);
    }
}
