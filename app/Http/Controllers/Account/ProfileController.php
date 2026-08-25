<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ProfileUpdateRequest;
use App\Models\Article;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('account.index', [
            'user' => $user,
            'stats' => [
                'bookmarks' => $user->bookmarks()->count(),
                'history' => $user->readingHistory()->count(),
                'comments' => $user->comments()->count(),
            ],
            'recent' => $user->readArticles()
                ->select(\App\Services\ArticleQuery::CARD_COLUMNS)
                ->with(['category:id,name,slug,path,color', 'author:id,name,slug,avatar'])
                ->limit(6)
                ->get(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($file = $request->file('avatar')) {
            // Replace rather than accumulate — an avatar has no history worth
            // keeping, and orphaned uploads add up fast.
            if ($user->avatar && ! str_starts_with($user->avatar, 'http')) {
                Storage::disk('public')->delete($user->avatar);
            }

            $data['avatar'] = $file->store('avatars', 'public');
        }

        $emailChanged = isset($data['email']) && $data['email'] !== $user->email;

        $user->fill($data);

        // Changing the email invalidates the previous verification.
        if ($emailChanged) {
            $user->forceFill(['email_verified_at' => null]);
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', 'প্রোফাইল হালনাগাদ হয়েছে।');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'বর্তমান পাসওয়ার্ড সঠিক নয়।',
            'password.confirmed' => 'নতুন পাসওয়ার্ড দুটি মিলছে না।',
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return back()->with('status', 'পাসওয়ার্ড পরিবর্তন হয়েছে।');
    }

    /** Account deletion — requires the password, and logs the session out. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('delete', [
            'password' => ['required', 'current_password'],
        ], [
            'password.current_password' => 'পাসওয়ার্ড সঠিক নয়।',
        ]);

        $user = $request->user();

        Auth::logout();
        $user->delete();          // soft delete — comments stay attributable

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'আপনার অ্যাকাউন্ট মুছে ফেলা হয়েছে।');
    }
}
