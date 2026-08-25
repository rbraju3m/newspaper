<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // One field for both, so readers who registered with a phone can
            // sign in with it without guessing which box to use.
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'ইমেইল বা মোবাইল নম্বর দিন।',
            'password.required' => 'পাসওয়ার্ড দিন।',
        ];
    }

    /** @throws ValidationException */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim($this->input('login'));
        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        $credentials = [
            $field => $field === 'email' ? mb_strtolower($login) : $this->normalisePhone($login),
            'password' => $this->input('password'),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => 'এই তথ্য দিয়ে কোনো অ্যাকাউন্ট পাওয়া যায়নি।',
            ]);
        }

        // A suspended account must not get a session even with a valid password.
        if (! $this->user()->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'আপনার অ্যাকাউন্টটি সাময়িকভাবে স্থগিত আছে।',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    private function normalisePhone(string $value): string
    {
        return strtr($value, [
            '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        ]);
    }

    /** @throws ValidationException */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => 'অনেকবার চেষ্টা করা হয়েছে। '
                .\App\Support\Bangla::digits(ceil($seconds / 60))
                .' মিনিট পর আবার চেষ্টা করুন।',
        ]);
    }

    /** Keyed on identifier + IP so one attacker cannot lock out a real reader. */
    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('login')).'|'.$this->ip());
    }
}
