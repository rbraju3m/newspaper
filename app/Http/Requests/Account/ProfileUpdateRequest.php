<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->user()->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users')->ignore($id)],
            'phone' => ['nullable', 'regex:/^01[3-9]\d{8}$/', Rule::unique('users')->ignore($id)],
            'bio' => ['nullable', 'string', 'max:500'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'এই ইমেইলটি অন্য একটি অ্যাকাউন্টে ব্যবহৃত হচ্ছে।',
            'phone.regex' => 'সঠিক মোবাইল নম্বর লিখুন (যেমন ০১৭XXXXXXXX)।',
            'avatar.image' => 'ছবি হিসেবে একটি বৈধ ইমেজ ফাইল দিন।',
            'avatar.max' => 'ছবির আকার সর্বোচ্চ ২ মেগাবাইট হতে পারে।',
        ];
    }
}
