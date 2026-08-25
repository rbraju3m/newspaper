<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Models\Media;
use App\Services\AdService;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AdController extends Controller
{
    public function index(): View
    {
        Gate::authorize('manage-site');

        return view('admin.ads.index', [
            'ads' => Ad::orderBy('position')->orderByDesc('priority')->get(),
            'slots' => config('site.ad_slots'),
        ]);
    }

    public function store(Request $request, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-site');

        Ad::create($this->validated($request, $images));
        AdService::flush();

        return back()->with('status', 'বিজ্ঞাপন যুক্ত হয়েছে।');
    }

    public function update(Request $request, Ad $ad, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-site');

        $previousAsset = $ad->asset;

        $ad->update($this->validated($request, $images, $ad));

        // After the write, so the ad's own old asset is gone and does not read
        // as a reference to itself. release() keeps the file if any other row
        // still points at it.
        if ($ad->asset !== $previousAsset) {
            $images->release($previousAsset);
        }

        AdService::flush();

        return back()->with('status', 'বিজ্ঞাপন হালনাগাদ হয়েছে।');
    }

    public function destroy(Ad $ad, ImageService $images): RedirectResponse
    {
        Gate::authorize('manage-site');

        $ad->delete();

        // After the delete, so the ad's own asset no longer counts as a
        // reference to itself.
        $images->release($ad->asset);
        AdService::flush();

        return back()->with('status', 'বিজ্ঞাপন মুছে ফেলা হয়েছে।');
    }

    private function validated(Request $request, ImageService $images, ?Ad $ad = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'position' => ['required', 'string', 'in:'.implode(',', array_keys(config('site.ad_slots')))],
            'type' => ['required', 'in:image,html,adsense'],
            'url' => ['nullable', 'url', 'max:2048'],
            'html' => ['nullable', 'string', 'max:8000'],
            'file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:2048'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [
            'ends_at.after' => 'শেষ তারিখ শুরুর তারিখের পরে হতে হবে।',
        ]);

        if ($file = $request->file('file')) {
            // Through the media library rather than straight to disk. A
            // creative stored with $file->store('ads', 'public') had no Media
            // row at all: nothing tracked it, nothing could re-derive it, and
            // media:backfill could not see it.
            $data['asset'] = $images->store($file, $request->user()?->id, [
                'alt' => $data['title'],
            ])->path;
        }

        unset($data['file']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
