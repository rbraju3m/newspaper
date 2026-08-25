@extends('layouts.auth')
@section('title', 'নতুন পাসওয়ার্ড — '.config('site.name_bn'))

@section('content')
    <div class="rounded-xl border border-line bg-surface p-6 shadow-card lg:p-8">
        <h1 class="font-headline text-2xl font-bold text-ink">নতুন পাসওয়ার্ড দিন</h1>

        <form method="POST" action="{{ route('password.store') }}" class="mt-5 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <x-form.input name="email" label="ইমেইল ঠিকানা" type="email" :value="$email"
                          :required="true" autocomplete="email" readonly
                          class="bg-surface-2" />

            <x-form.password name="password" label="নতুন পাসওয়ার্ড" autocomplete="new-password"
                             hint="কমপক্ষে ৮ অক্ষর ব্যবহার করুন।" />
            <x-form.password name="password_confirmation" label="পাসওয়ার্ড নিশ্চিত করুন"
                             autocomplete="new-password" />

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2.5 text-base font-semibold text-white
                           transition hover:bg-brand-700">
                পাসওয়ার্ড পরিবর্তন করুন
            </button>
        </form>
    </div>
@endsection
