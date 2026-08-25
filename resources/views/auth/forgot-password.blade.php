@extends('layouts.auth')
@section('title', 'পাসওয়ার্ড রিসেট — '.config('site.name_bn'))

@section('content')
    <div class="rounded-xl border border-line bg-surface p-6 shadow-card lg:p-8">
        <h1 class="font-headline text-2xl font-bold text-ink">পাসওয়ার্ড ভুলে গেছেন?</h1>
        <p class="mt-1 text-sm text-muted">
            ইমেইল ঠিকানা দিন — আমরা পাসওয়ার্ড রিসেট করার লিংক পাঠিয়ে দেব।
        </p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-4">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-5 space-y-4">
            @csrf
            <x-form.input name="email" label="ইমেইল ঠিকানা" type="email" :required="true" autocomplete="email" />
            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2.5 text-base font-semibold text-white
                           transition hover:bg-brand-700">
                রিসেট লিংক পাঠান
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-muted">
            <a href="{{ route('login') }}" class="font-semibold text-link hover:text-brand">লগইনে ফিরে যান</a>
        </p>
    </div>
@endsection
