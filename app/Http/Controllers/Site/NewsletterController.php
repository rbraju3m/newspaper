<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Mail\NewsletterVerify;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

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

        $this->sendVerification($subscriber);

        // The same answer whether the address was new, already subscribed, or
        // already confirmed. Anything else turns this box into an oracle for
        // "is this person a reader of ours", which is not ours to disclose.
        return back()->with('status', 'ধন্যবাদ! নিশ্চিত করতে আপনার ইমেইল দেখুন।');
    }

    /**
     * The double opt-in mail.
     *
     * Sent inline: this deployment runs no queue worker, so a queued mail would
     * sit in the `jobs` table for ever. The endpoint is throttled to five an
     * hour, which is what makes paying SMTP latency in a request acceptable.
     *
     * A send that throws must not take the request with it. The row is already
     * written and the reader has already been told to check their inbox; a 500
     * here would leave them staring at an error while the subscription quietly
     * exists. It is logged instead, which is what `storage/logs` is for.
     */
    private function sendVerification(NewsletterSubscriber $subscriber): void
    {
        // Nothing to confirm — an account holder whose email is already
        // verified is signed up through PreferenceController without this step.
        if ($subscriber->verified_at) {
            return;
        }

        try {
            Mail::to($subscriber->email)->send(new NewsletterVerify($subscriber));
        } catch (\Throwable $e) {
            Log::warning('Newsletter verification mail failed', [
                'subscriber' => $subscriber->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function verify(NewsletterSubscriber $subscriber): RedirectResponse
    {
        $subscriber->forceFill(['verified_at' => now(), 'unsubscribed_at' => null])->save();

        return redirect()->route('home')->with('status', 'আপনার সাবস্ক্রিপশন নিশ্চিত হয়েছে।');
    }

    /**
     * The unsubscribe link asks before it acts.
     *
     * It used to unsubscribe on GET, which is a real way to lose readers:
     * Gmail, Outlook and every corporate mail scanner fetch the links in a
     * message to check them, and a GET that changes state is a GET they will
     * trigger. The reader never clicked anything and the newsletter stops.
     *
     * So the link renders a confirmation, and the button posts. One-click
     * unsubscribe from the mail client's own chrome goes straight to `destroy()`
     * via the `List-Unsubscribe-Post` header — that is a real intent signal
     * from a real reader, and RFC 8058 requires it be honoured without a page.
     */
    public function confirm(NewsletterSubscriber $subscriber): View
    {
        return view('site.newsletter-unsubscribe', ['subscriber' => $subscriber]);
    }

    public function destroy(Request $request, NewsletterSubscriber $subscriber): Response|RedirectResponse
    {
        $subscriber->forceFill(['unsubscribed_at' => now()])->save();

        // RFC 8058: the mail client posts this itself and renders nothing, so
        // there is nobody to redirect. A 302 to the homepage would have it
        // fetch the homepage for no reason.
        if ($request->hasHeader('List-Unsubscribe') || $request->input('List-Unsubscribe') === 'One-Click') {
            return response('', 200);
        }

        return redirect()->route('home')->with('status', 'আপনাকে আর নিউজলেটার পাঠানো হবে না।');
    }
}
