<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\NewsletterSubscriber;
use App\Models\PushSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PreferenceController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.preferences', [
            'categories' => Category::active()->roots()->orderBy('position')->get(['id', 'name', 'slug', 'color']),
            'subscriber' => NewsletterSubscriber::where('email', $request->user()->email)->first(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'followed_categories' => ['nullable', 'array'],
            'followed_categories.*' => ['integer', 'exists:categories,id'],
            'newsletter' => ['nullable', 'boolean'],
            'newsletter_frequency' => ['nullable', 'in:daily,weekly'],
            'breaking_alerts' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        $user->forceFill([
            'preferences' => [
                'followed_categories' => $validated['followed_categories'] ?? [],
                'breaking_alerts' => $request->boolean('breaking_alerts'),
            ],
        ])->save();

        $this->syncNewsletter($request, $validated);
        $this->syncPush($request);

        return back()->with('status', 'পছন্দসমূহ সংরক্ষিত হয়েছে।');
    }

    /**
     * Makes the account-wide breaking-news switch mean something.
     *
     * Turning it off stands down every browser this reader has subscribed on,
     * which is the half a server can do. Turning it back on re-arms those same
     * rows — it cannot *create* one, because only the browser can grant the
     * permission, which is why the preferences screen carries a per-browser
     * toggle beside this checkbox rather than instead of it.
     *
     * A query-builder update: `breaking` is fillable but there is no model to
     * fill, and no event on this table wants firing.
     */
    private function syncPush(Request $request): void
    {
        PushSubscription::where('user_id', $request->user()->id)
            ->update(['breaking' => $request->boolean('breaking_alerts')]);
    }

    private function syncNewsletter(Request $request, array $validated): void
    {
        $user = $request->user();
        $subscriber = NewsletterSubscriber::where('email', $user->email)->first();

        if (! $request->boolean('newsletter')) {
            $subscriber?->forceFill(['unsubscribed_at' => now()])->save();

            return;
        }

        NewsletterSubscriber::updateOrCreate(
            ['email' => $user->email],
            [
                'user_id' => $user->id,
                'name' => $user->name,
                'frequency' => $validated['newsletter_frequency'] ?? 'daily',
                'categories' => $validated['followed_categories'] ?? [],
                'unsubscribed_at' => null,
                // Already-verified account email needs no second confirmation.
                'verified_at' => $subscriber?->verified_at ?? ($user->hasVerifiedEmail() ? now() : null),
                'ip' => $request->ip(),
            ],
        );
    }
}
