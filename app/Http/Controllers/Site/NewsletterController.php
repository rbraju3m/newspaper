<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NewsletterController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $subscriber = NewsletterSubscriber::firstOrNew(['email' => $validated['email']]);

        // Re-subscribing after an unsubscribe should just work.
        $subscriber->fill([
            'name' => $validated['name'] ?? $subscriber->name,
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'unsubscribed_at' => null,
        ])->save();

        // TODO(Phase 3): dispatch the double opt-in verification mail.

        return back()->with('status', 'ধন্যবাদ! নিশ্চিত করতে আপনার ইমেইল দেখুন।');
    }

    public function verify(NewsletterSubscriber $subscriber): RedirectResponse
    {
        $subscriber->forceFill(['verified_at' => now(), 'unsubscribed_at' => null])->save();

        return redirect()->route('home')->with('status', 'আপনার সাবস্ক্রিপশন নিশ্চিত হয়েছে।');
    }

    public function unsubscribe(NewsletterSubscriber $subscriber): RedirectResponse
    {
        $subscriber->forceFill(['unsubscribed_at' => now()])->save();

        return redirect()->route('home')->with('status', 'আপনাকে আর নিউজলেটার পাঠানো হবে না।');
    }
}
