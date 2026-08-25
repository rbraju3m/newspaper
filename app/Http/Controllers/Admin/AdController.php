<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\AdService;
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

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-site');

        Ad::create($this->validated($request));
        AdService::flush();

        return back()->with('status', 'বিজ্ঞাপন যুক্ত হয়েছে।');
    }

    public function update(Request $request, Ad $ad): RedirectResponse
    {
        Gate::authorize('manage-site');

        $ad->update($this->validated($request, $ad));
        AdService::flush();

        return back()->with('status', 'বিজ্ঞাপন হালনাগাদ হয়েছে।');
    }

    public function destroy(Ad $ad): RedirectResponse
    {
        Gate::authorize('manage-site');

        if ($ad->asset && ! str_starts_with($ad->asset, 'http')) {
            Storage::disk('public')->delete($ad->asset);
        }

        $ad->delete();
        AdService::flush();

        return back()->with('status', 'বিজ্ঞাপন মুছে ফেলা হয়েছে।');
    }

    private function validated(Request $request, ?Ad $ad = null): array
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
            if ($ad?->asset && ! str_starts_with($ad->asset, 'http')) {
                Storage::disk('public')->delete($ad->asset);
            }

            $data['asset'] = $file->store('ads', 'public');
        }

        unset($data['file']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
