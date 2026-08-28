<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
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

        // Deletion is permanent — the account page says so — and the address
        // stays spoken for, because the soft-deleted row keeps the unique
        // index. Say that, rather than the "no email" message this used to
        // fall through to, which sent people off to a registration that
        // cannot succeed either.
        if ($user->trashed()) {
            return redirect()->route('login')->withErrors([
                'login' => 'এই অ্যাকাউন্টটি মুছে ফেলা হয়েছে। নতুন করে শুরু করতে অন্য একটি ইমেইল ব্যবহার করুন।',
            ]);
        }

        if (! $user->isActive()) {
            return redirect()->route('login')
                ->withErrors(['login' => 'আপনার অ্যাকাউন্টটি সাময়িকভাবে স্থগিত আছে।']);
        }

        // A reader whose provider did not verify the address is created
        // unstamped, so they need the same mail a form registration sends.
        // Fired here rather than inside the transaction: nothing can unsend a
        // message a rollback decided never happened.
        if ($user->wasRecentlyCreated && ! $user->hasVerifiedEmail()) {
            event(new Registered($user));
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
     *
     * A soft-deleted account is returned trashed from either lookup rather
     * than as null, because the caller has a different thing to say about it
     * and because its row still holds the unique index on the address.
     */
    private function resolveUser(string $provider, \Laravel\Socialite\Contracts\User $social): ?User
    {
        $existing = SocialAccount::where('provider', $provider)
            ->where('provider_id', $social->getId())
            ->first();

        if ($existing) {
            // `withTrashed`, and a query rather than a read off the relation.
            // A deleted reader has to come back as a *deleted user* so the
            // caller can say so, instead of a null that the caller can only
            // report as "the provider sent no email". Explicit because strict
            // mode does not catch a lazy load on a single-row fetch — see
            // CLAUDE.md.
            $user = $existing->user()->withTrashed()->first();

            // A profile picture changes at the provider, and this is the only
            // path a returning reader takes — so it is the only place the
            // stored copy can follow it. Written only when it differs, so an
            // ordinary sign-in stays a read.
            if ($user && ! $user->trashed() && $existing->avatar !== $social->getAvatar()) {
                $existing->update(['avatar' => $social->getAvatar()]);
            }

            return $user;
        }

        $email = $social->getEmail() ? mb_strtolower($social->getEmail()) : null;

        if (! $email) {
            return null;
        }

        return DB::transaction(function () use ($provider, $social, $email) {
            // `withTrashed`: a soft-deleted row still holds the unique index
            // on this address, so `User::create()` below would be a duplicate
            // key rather than a new reader. Handed back trashed for the caller
            // to refuse by name.
            $user = User::withTrashed()->where('email', $email)->first();

            if ($user?->trashed()) {
                return $user;
            }

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

            SocialAccount::updateOrCreate(
                ['provider' => $provider, 'provider_id' => $social->getId()],
                ['user_id' => $user->id, 'avatar' => $social->getAvatar()],
            );

            // Only a provider that says it verified the address has proven
            // anything. Stamping regardless invents a verification nobody
            // performed — and the account it produces can sit on an address
            // whose real owner has not signed up yet. An unverified one is
            // created unstamped and goes through the ordinary mail; the
            // caller sends it, outside this transaction.
            //
            // `email_verified_at` is guarded, hence forceFill.
            if (! $user->email_verified_at && $this->providerEmailIsVerified($social)) {
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
