<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\HomepageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * key => [type, label, group]. Anything not listed here is ignored on
     * submit, so a crafted form field cannot write arbitrary settings rows.
     */
    private const SCHEMA = [
        'site_name' => ['string', 'সাইটের নাম', 'general'],
        'site_tagline' => ['string', 'ট্যাগলাইন', 'general'],
        'site_description' => ['string', 'সাইটের বিবরণ', 'general'],
        'editor_name' => ['string', 'সম্পাদকের নাম', 'imprint'],
        'publisher_name' => ['string', 'প্রকাশকের নাম', 'imprint'],
        'office_address' => ['string', 'ঠিকানা', 'imprint'],
        'office_phone' => ['string', 'ফোন', 'imprint'],
        'office_email' => ['string', 'ইমেইল', 'imprint'],
        'comments_require_approval' => ['bool', 'মন্তব্য অনুমোদনের পর প্রকাশ', 'comments'],
        'comments_min_length' => ['int', 'মন্তব্যের সর্বনিম্ন দৈর্ঘ্য', 'comments'],
        'articles_per_page' => ['int', 'প্রতি পাতায় খবর', 'display'],
        'show_reading_time' => ['bool', 'পড়ার সময় দেখান', 'display'],
        'breaking_ticker_enabled' => ['bool', 'ব্রেকিং টিকার চালু', 'display'],
        'live_stream_url' => ['string', 'লাইভ স্ট্রিম লিংক', 'display'],
        'google_analytics_id' => ['string', 'Google Analytics ID', 'integration'],
        'facebook_app_id' => ['string', 'Facebook App ID', 'integration'],
    ];

    public function edit(): View
    {
        Gate::authorize('manage-site');

        return view('admin.settings.edit', [
            'schema' => collect(self::SCHEMA)->map(fn ($m, $k) => [
                'key' => $k, 'type' => $m[0], 'label' => $m[1], 'group' => $m[2],
                'value' => Setting::get($k),
            ])->groupBy('group'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-site');

        foreach (self::SCHEMA as $key => [$type, $label, $group]) {
            $value = match ($type) {
                'bool' => $request->boolean($key) ? '1' : '0',
                'int' => (string) max(0, (int) $request->input($key, 0)),
                default => (string) $request->input($key, ''),
            };

            Setting::put($key, $value, $type, $group);
        }

        // Settings feed the layout and homepage, so both caches must go.
        Cache::forget('layout.categories');
        HomepageService::flush();

        return back()->with('status', 'সেটিংস সংরক্ষিত হয়েছে।');
    }
}
