<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        // Views link to /login?redirect=/current/page so a reader who clicks
        // "bookmark" lands back on the story after signing in.
        if ($request->filled('redirect')) {
            $request->session()->put('url.intended', $request->string('redirect')->toString());
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Rotate the session id — without this, a session fixed before login
        // stays valid after it.
        $request->session()->regenerate();

        $request->user()->forceFill([
            'last_seen_at' => now(),
            'last_ip' => $request->ip(),
        ])->saveQuietly();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'আপনি লগআউট করেছেন।');
    }
}
