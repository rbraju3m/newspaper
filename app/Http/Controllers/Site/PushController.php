<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where a browser registers itself for notifications.
 *
 * Guests included, and that is the point: most readers of a news site are not
 * signed in, and breaking news is exactly what they want a notification for.
 * The browser's own permission prompt is the consent record. `user_id` is a
 * label a subscription acquires if somebody happens to be signed in on that
 * browser — it is what lets the account preferences screen speak for a
 * subscription it did not create, and nothing more.
 *
 * Identity is the endpoint, so the same browser subscribing twice updates one
 * row rather than accumulating them. A reader who clears site data and
 * re-subscribes gets a genuinely new endpoint and a genuinely new row; the old
 * one is unreachable and gets pruned the first time an alert goes out.
 */
class PushController extends Controller
{
    public function store(Request $request, PushService $push): JsonResponse
    {
        if (! $push->configured()) {
            // 503 rather than 404: the endpoint exists, the server just cannot
            // honour it. The client reads this and stops offering the toggle.
            return response()->json(['message' => 'পুশ নোটিফিকেশন এখন চালু নেই।'], 503);
        }

        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $request->user()?->id,
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => str($request->userAgent() ?? '')->limit(500, '')->value() ?: null,
                'breaking' => true,
            ],
        );

        return response()->json(['message' => 'ব্রেকিং নিউজ অ্যালার্ট চালু হয়েছে।']);
    }

    /**
     * Unsubscribes one browser.
     *
     * Answers 200 whether or not a row was there. The client has already told
     * the browser to drop the subscription by the time this arrives, so the
     * endpoint is gone either way and a 404 would only give the page an error
     * to report about something that is, in fact, exactly as the reader asked.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        PushSubscription::where('endpoint', $validated['endpoint'])->delete();

        return response()->json(['message' => 'অ্যালার্ট বন্ধ হয়েছে।']);
    }
}
