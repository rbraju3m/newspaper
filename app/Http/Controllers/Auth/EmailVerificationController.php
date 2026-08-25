<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('account.index')
            : view('auth.verify-email');
    }

    /**
     * The signed-URL check lives in EmailVerificationRequest, so a tampered id
     * or hash never reaches this method.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.index');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->route('account.index')
            ->with('status', 'আপনার ইমেইল যাচাই সম্পন্ন হয়েছে।');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.index');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'যাচাইকরণ লিংক আবার পাঠানো হয়েছে।');
    }
}
