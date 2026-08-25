@extends('layouts.auth')
@section('title', 'নিবন্ধন — '.config('site.name_bn'))

@section('content')
    <div class="rounded-xl border border-line bg-surface p-6 shadow-card lg:p-8">
        <h1 class="font-headline text-2xl font-bold text-ink">নতুন অ্যাকাউন্ট</h1>
        <p class="mt-1 text-sm text-muted">বিনামূল্যে নিবন্ধন করে খবর সংরক্ষণ ও মন্তব্য করুন।</p>

        @error('login')
            <x-ui.alert type="error" class="mt-4">{{ $message }}</x-ui.alert>
        @enderror

        <form method="POST" action="{{ route('register.store') }}" class="mt-5 space-y-4">
            @csrf

            <x-form.input name="name" label="পূর্ণ নাম" :required="true" autocomplete="name" />
            <x-form.input name="email" label="ইমেইল ঠিকানা" type="email" :required="true"
                          autocomplete="email" inputmode="email" />
            <x-form.input name="phone" label="মোবাইল নম্বর" type="tel" autocomplete="tel"
                          inputmode="tel" hint="মোবাইল দিয়েও লগইন করতে পারবেন।" />

            <x-form.password name="password" autocomplete="new-password"
                             hint="কমপক্ষে ৮ অক্ষর ব্যবহার করুন।" />
            <x-form.password name="password_confirmation" label="পাসওয়ার্ড নিশ্চিত করুন"
                             autocomplete="new-password" />

            <label class="flex cursor-pointer items-start gap-2.5 text-sm text-body">
                <input type="checkbox" name="newsletter" value="1" @checked(old('newsletter'))
                       class="mt-0.5 rounded border-line-strong accent-[var(--color-brand)]">
                <span>প্রতিদিনের বাছাই করা খবর ইমেইলে পেতে চাই</span>
            </label>

            <div>
                <label class="flex cursor-pointer items-start gap-2.5 text-sm text-body">
                    <input type="checkbox" name="terms" value="1" @checked(old('terms')) required
                           class="mt-0.5 rounded border-line-strong accent-[var(--color-brand)]">
                    <span>
                        আমি
                        <a href="{{ route('page.show', 'terms') }}" target="_blank"
                           class="font-medium text-link hover:text-brand">ব্যবহারের শর্তাবলি</a> ও
                        <a href="{{ route('page.show', 'privacy') }}" target="_blank"
                           class="font-medium text-link hover:text-brand">গোপনীয়তা নীতি</a>
                        মেনে নিচ্ছি
                    </span>
                </label>
                @error('terms')
                    <p class="mt-1.5 text-xs font-medium text-brand">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2.5 text-base font-semibold text-white
                           transition hover:bg-brand-700">
                নিবন্ধন করুন
            </button>
        </form>

        <x-auth.social-buttons />

        <p class="mt-6 text-center text-sm text-muted">
            ইতিমধ্যে অ্যাকাউন্ট আছে?
            <a href="{{ route('login') }}" class="font-semibold text-link hover:text-brand">লগইন করুন</a>
        </p>
    </div>
@endsection
