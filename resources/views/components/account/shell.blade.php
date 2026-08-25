@props(['title', 'active'])

@php
    $nav = [
        'index' => ['user', 'প্রোফাইল', 'account.index'],
        'bookmarks' => ['bookmark', 'সংরক্ষিত খবর', 'account.bookmarks'],
        'history' => ['clock', 'পড়ার ইতিহাস', 'account.history'],
        'preferences' => ['settings', 'পছন্দসমূহ', 'account.preferences'],
    ];
@endphp

<div class="mx-auto max-w-site px-4 py-6 lg:py-8">
    <h1 class="font-headline text-2xl font-bold text-ink lg:text-3xl">{{ $title }}</h1>

    <div class="mt-5 grid gap-6 lg:grid-cols-12 lg:gap-8">
        <aside class="lg:col-span-3">
            {{-- Horizontal tab rail on mobile, vertical list on desktop --}}
            <nav class="no-scrollbar flex gap-1 overflow-x-auto rounded-xl border border-line
                        bg-surface p-1.5 lg:flex-col lg:gap-0.5 lg:p-2"
                 aria-label="অ্যাকাউন্ট মেনু">
                @foreach ($nav as $key => [$icon, $label, $route])
                    <a href="{{ route($route) }}"
                       @class([
                           'flex shrink-0 items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition',
                           'bg-brand text-white' => $active === $key,
                           'text-body hover:bg-surface-2' => $active !== $key,
                       ])
                       @if ($active === $key) aria-current="page" @endif>
                        <x-ui.icon name="{{ $icon }}" class="h-4 w-4" />
                        <span class="whitespace-nowrap">{{ $label }}</span>
                    </a>
                @endforeach

                <form method="POST" action="{{ route('logout') }}" class="lg:mt-1 lg:border-t lg:border-line lg:pt-1">
                    @csrf
                    <button type="submit"
                            class="flex w-full shrink-0 items-center gap-2.5 rounded-lg px-3 py-2
                                   text-sm font-medium text-body transition hover:bg-surface-2">
                        <x-ui.icon name="logout" class="h-4 w-4" />
                        <span class="whitespace-nowrap">লগআউট</span>
                    </button>
                </form>
            </nav>
        </aside>

        <div class="lg:col-span-9">
            @if (session('status'))
                <x-ui.alert type="success" class="mb-5">{{ session('status') }}</x-ui.alert>
            @endif

            @if (! auth()->user()->hasVerifiedEmail())
                <x-ui.alert type="warning" class="mb-5" title="ইমেইল যাচাই করা হয়নি">
                    মন্তব্য করতে ইমেইল যাচাই প্রয়োজন।
                    <a href="{{ route('verification.notice') }}" class="font-semibold underline">এখনই যাচাই করুন</a>
                </x-ui.alert>
            @endif

            {{ $slot }}
        </div>
    </div>
</div>
