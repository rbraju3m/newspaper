@extends('layouts.auth')
@section('title', 'লগইন — '.config('site.name_bn'))

@section('content')
    <div class="rounded-xl border border-line bg-surface p-6 shadow-card lg:p-8">
        <h1 class="font-headline text-2xl font-bold text-ink">লগইন করুন</h1>
        <p class="mt-1 text-sm text-muted">আপনার অ্যাকাউন্টে প্রবেশ করে খবর সংরক্ষণ করুন ও মন্তব্য করুন।</p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-4">{{ session('status') }}</x-ui.alert>
        @endif

        @error('login')
            <x-ui.alert type="error" class="mt-4">{{ $message }}</x-ui.alert>
        @enderror

        <form method="POST" action="{{ route('login.store') }}" class="mt-5 space-y-4">
            @csrf

            <x-form.input name="login" label="ইমেইল বা মোবাইল নম্বর" :required="true"
                          autocomplete="username" inputmode="email"
                          placeholder="you@example.com বা ০১৭XXXXXXXX" />

            <div>
                <x-form.password name="password" autocomplete="current-password" />
                <div class="mt-2 flex items-center justify-between">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-body">
                        <input type="checkbox" name="remember" value="1" @checked(old('remember'))
                               class="rounded border-line-strong accent-[var(--color-brand)]">
                        মনে রাখুন
                    </label>
                    <a href="{{ route('password.request') }}" class="text-sm font-medium text-link hover:text-brand">
                        পাসওয়ার্ড ভুলে গেছেন?
                    </a>
                </div>
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2.5 text-base font-semibold text-white
                           transition hover:bg-brand-700">
                লগইন
            </button>
        </form>

        <x-auth.social-buttons />

        <p class="mt-6 text-center text-sm text-muted">
            অ্যাকাউন্ট নেই?
            <a href="{{ route('register') }}" class="font-semibold text-link hover:text-brand">নিবন্ধন করুন</a>
        </p>
    </div>
@endsection
