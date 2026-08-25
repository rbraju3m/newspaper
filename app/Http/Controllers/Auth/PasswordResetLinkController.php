<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email:rfc', 'max:190']],
            ['email.required' => 'ইমেইল ঠিকানা লিখুন।', 'email.email' => 'সঠিক ইমেইল ঠিকানা লিখুন।'],
        );

        Password::sendResetLink($request->only('email'));

        // Always the same response, whether or not the address exists — a
        // differing message turns this form into an account-enumeration oracle.
        return back()->with('status', 'যদি এই ইমেইলে কোনো অ্যাকাউন্ট থাকে, রিসেট লিংক পাঠানো হয়েছে।');
    }
}
