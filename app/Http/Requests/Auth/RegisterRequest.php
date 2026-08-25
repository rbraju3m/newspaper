<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190', 'unique:users,email'],
            // Optional, but unique when given — many Bangladeshi readers sign in
            // by phone, so the column has to stay collision-free from day one.
            'phone' => ['nullable', 'string', 'regex:/^01[3-9]\d{8}$/', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'terms' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'আপনার নাম লিখুন।',
            'name.min' => 'নাম কমপক্ষে ২ অক্ষরের হতে হবে।',
            'email.required' => 'ইমেইল ঠিকানা লিখুন।',
            'email.email' => 'সঠিক ইমেইল ঠিকানা লিখুন।',
            'email.unique' => 'এই ইমেইল দিয়ে ইতিমধ্যে একটি অ্যাকাউন্ট আছে।',
            'phone.regex' => 'সঠিক মোবাইল নম্বর লিখুন (যেমন ০১৭XXXXXXXX)।',
            'phone.unique' => 'এই মোবাইল নম্বরটি ইতিমধ্যে ব্যবহৃত হয়েছে।',
            'password.required' => 'পাসওয়ার্ড দিন।',
            'password.confirmed' => 'পাসওয়ার্ড দুটি মিলছে না।',
            'terms.accepted' => 'ব্যবহারের শর্তাবলিতে সম্মতি দিন।',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Readers routinely type Bangla digits into the phone field.
        if ($this->filled('phone')) {
            $this->merge([
                'phone' => strtr($this->input('phone'), [
                    '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
                    '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
                ]),
            ]);
        }

        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }
}
