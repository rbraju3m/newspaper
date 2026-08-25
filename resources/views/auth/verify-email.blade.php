@extends('layouts.auth')
@section('title', 'ইমেইল যাচাই — '.config('site.name_bn'))

@section('content')
    <div class="rounded-xl border border-line bg-surface p-6 text-center shadow-card lg:p-8">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-brand-50 dark:bg-brand-900/30">
            <x-ui.icon name="mail" class="h-7 w-7 text-brand" />
        </div>

        <h1 class="mt-4 font-headline text-2xl font-bold text-ink">ইমেইল যাচাই করুন</h1>
        <p class="mt-2 text-sm text-body">
            <span class="font-semibold text-ink">{{ auth()->user()->email }}</span> ঠিকানায় একটি
            যাচাইকরণ লিংক পাঠানো হয়েছে। মন্তব্য করতে ও সব সুবিধা পেতে লিংকটিতে ক্লিক করুন।
        </p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-4 text-start">{{ session('status') }}</x-ui.alert>
        @endif

        <div class="mt-5 flex flex-col gap-2 sm:flex-row">
            <form method="POST" action="{{ route('verification.send') }}" class="flex-1">
                @csrf
                <button type="submit"
                        class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white
                               transition hover:bg-brand-700">
                    আবার পাঠান
                </button>
            </form>

            <a href="{{ route('account.index') }}"
               class="flex-1 rounded-lg border border-line-strong px-4 py-2.5 text-sm
                      font-semibold text-ink transition hover:border-brand hover:text-brand">
                পরে করব
            </a>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button type="submit" class="text-sm text-muted hover:text-brand">লগআউট</button>
        </form>
    </div>
@endsection
