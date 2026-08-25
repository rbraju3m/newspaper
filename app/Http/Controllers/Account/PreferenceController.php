<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\NewsletterSubscriber;
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

        return back()->with('status', 'পছন্দসমূহ সংরক্ষিত হয়েছে।');
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
