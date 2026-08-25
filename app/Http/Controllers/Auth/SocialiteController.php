<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SocialiteController extends Controller
{
    private const PROVIDERS = ['google', 'facebook'];

    public function redirect(string $provider): RedirectResponse
    {
        $this->guardProvider($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $this->guardProvider($provider);

        try {
            $social = Socialite::driver($provider)->user();
        } catch (\Throwable $e) {
            Log::warning('OAuth callback failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return redirect()->route('login')
                ->withErrors(['login' => 'সোশ্যাল লগইন সম্পন্ন করা যায়নি। আবার চেষ্টা করুন।']);
        }

        if (! $social->getId()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'সোশ্যাল অ্যাকাউন্ট থেকে তথ্য পাওয়া যায়নি।']);
        }

        $user = $this->resolveUser($provider, $social);

        if (! $user) {
            return redirect()->route('login')->withErrors([
                'login' => 'এই সোশ্যাল অ্যাকাউন্টে কোনো ইমেইল নেই, তাই অ্যাকাউন্ট তৈরি করা যায়নি। '
                    .'ইমেইল দিয়ে নিবন্ধন করুন।',
            ]);
        }

        if (! $user->isActive()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'আপনার অ্যাকাউন্টটি সাময়িকভাবে স্থগিত আছে।']);
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    /**
     * Three cases, in priority order:
     *   1. We have seen this provider identity before  → log that user in.
     *   2. The verified provider email matches a local account → link them.
     *   3. Neither → create a new reader.
     *
     * Case 2 is the dangerous one. We only auto-link when the provider asserts
     * the email is verified; otherwise anyone who can set an unverified address
     * at the provider could take over a local account by that email.
     */
    private function resolveUser(string $provider, \Laravel\Socialite\Contracts\User $social): ?User
    {
        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_id', $social->getId())
            ->first();

        if ($existing) {
            return $existing->user;
        }

        $email = $social->getEmail() ? mb_strtolower($social->getEmail()) : null;

        if (! $email) {
            return null;
        }

        return DB::transaction(function () use ($provider, $social, $email) {
            $user = User::where('email', $email)->first();

            if ($user && ! $this->providerEmailIsVerified($social)) {
                // Do not silently link. Returning the user here would be an
                // account-takeover path; instead we refuse and let them sign in
                // with their password.
                return null;
            }

            $user ??= User::create([
                'name' => $social->getName() ?: Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(32),   // unusable until they reset
                'role' => UserRole::Reader,
                'status' => 'active',
                'avatar' => $social->getAvatar(),
            ]);

            // The provider has already proven control of this address, but
            // email_verified_at is guarded, so it is set explicitly below.

            SocialAccount::updateOrCreate(
                ['provider' => $provider, 'provider_id' => $social->getId()],
                ['user_id' => $user->id, 'avatar' => $social->getAvatar()],
            );

            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });
    }

    /**
     * Google returns `email_verified` (or `verified_email` on the older
     * endpoint). Facebook only returns emails it has already confirmed.
     */
    private function providerEmailIsVerified(\Laravel\Socialite\Contracts\User $social): bool
    {
        $raw = method_exists($social, 'getRaw') ? $social->getRaw() : [];

        return (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? $raw['verified'] ?? false);
    }

    private function guardProvider(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new NotFoundHttpException;
        }

        if (! config("services.$provider.client_id")) {
            throw new NotFoundHttpException;
        }
    }
}
