<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'phone' => $request->validated('phone'),
                'password' => $request->validated('password'),
                'role' => UserRole::Reader,
                'status' => 'active',
            ]);

            // Newsletter opt-in is a separate checkbox — consent for an account
            // is not consent to be emailed a daily digest.
            if ($request->boolean('newsletter')) {
                NewsletterSubscriber::updateOrCreate(
                    ['email' => $user->email],
                    [
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'ip' => $request->ip(),
                        'unsubscribed_at' => null,
                    ],
                );
            }

            return $user;
        });

        event(new Registered($user));

        Auth::login($user, remember: true);

        return redirect()
            ->intended(route('verification.notice'))
            ->with('status', 'স্বাগতম! আপনার ইমেইল যাচাই করতে ইনবক্স দেখুন।');
    }
}
