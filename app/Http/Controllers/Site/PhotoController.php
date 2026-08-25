<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PhotoController extends Controller
{
    public function index(): View
    {
        return view('site.photo-index', [
            'galleries' => Gallery::published()
                ->withCount('images')
                ->with('category:id,name,path')
                ->latest('published_at')
                ->paginate(18),
        ]);
    }

    public function show(Gallery $gallery): View
    {
        if ($gallery->status !== 'published') {
            throw new NotFoundHttpException;
        }

        $gallery->increment('views');

        return view('site.photo-show', [
            'gallery' => $gallery->load(['images.media:id,disk,path,conversions', 'category']),
            'more' => Gallery::published()
                ->whereKeyNot($gallery->id)
                ->withCount('images')
                ->latest('published_at')
                ->limit(6)
                ->get(),
        ]);
    }
}
