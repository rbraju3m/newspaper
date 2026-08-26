<!DOCTYPE html>
<html lang="bn" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <script>
        (function () {
            try {
                var m = JSON.parse(localStorage.getItem('np_theme') || '"system"');
                var dark = m === 'dark' || (m === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>

    <title>@yield('title', 'অ্যাডমিন') — {{ config('site.name_bn') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="h-full bg-canvas text-body antialiased">
<div class="flex min-h-full" x-data="{ sidebar: false }">

    {{-- Sidebar: off-canvas below lg, fixed above --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         class="fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

    <aside class="fixed inset-y-0 start-0 z-40 w-64 shrink-0 border-e border-line bg-surface
                  transition-transform lg:static lg:translate-x-0"
           :class="sidebar ? 'translate-x-0' : '-translate-x-full rtl:translate-x-full'">
        <div class="flex h-full flex-col">
            <div class="flex items-center justify-between border-b border-line px-4 py-3.5">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand
                                 font-headline text-sm font-bold text-white">সং</span>
                    <span class="font-headline text-base font-bold text-ink">অ্যাডমিন</span>
                </a>
                <button type="button" @click="sidebar = false" class="rounded-md p-1.5 hover:bg-surface-2 lg:hidden">
                    <x-ui.icon name="close" class="h-5 w-5" />
                </button>
            </div>

            <nav class="flex-1 space-y-0.5 overflow-y-auto p-3" aria-label="অ্যাডমিন মেনু">
                @php
                    $user = auth()->user();
                    $nav = [
                        ['admin.dashboard', 'monitor', 'ড্যাশবোর্ড', true, null],
                        ['admin.articles.index', 'newspaper', 'খবর', true, null],
                        ['admin.media.index', 'camera', 'মিডিয়া', true, null],
                        ['admin.comments.index', 'comment', 'মন্তব্য', $user->role->canModerate(), $pendingComments ?? 0],
                        ['admin.categories.index', 'menu', 'বিভাগ', $user->role->canPublish(), null],
                        ['admin.taxonomy.index', 'bookmark', 'ট্যাগ ও বিষয়', $user->role->canPublish(), null],
                        ['admin.layout.index', 'settings', 'প্রচ্ছদ সাজান', $user->role->canPublish(), null],
                        ['admin.galleries.index', 'camera', 'ফটো গ্যালারি', $user->role->canPublish(), null],
                        ['admin.epapers.index', 'copy', 'ই-পেপার', $user->role->canPublish(), null],
                        ['admin.ads.index', 'eye', 'বিজ্ঞাপন', $user->role->canManageSite(), null],
                        ['admin.pages.index', 'copy', 'স্ট্যাটিক পাতা', $user->role->canManageSite(), null],
                        ['admin.users.index', 'user', 'ব্যবহারকারী', $user->role->canManageSite(), null],
                        ['admin.settings', 'settings', 'সেটিংস', $user->role->canManageSite(), null],
                    ];
                @endphp

                @foreach ($nav as [$route, $icon, $label, $visible, $badge])
                    @continue(! $visible)
                    @php $active = request()->routeIs($route) || request()->routeIs(Str::beforeLast($route, '.').'.*'); @endphp
                    <a href="{{ route($route) }}"
                       @class([
                           'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition',
                           'bg-brand text-white' => $active,
                           'text-body hover:bg-surface-2' => ! $active,
                       ])>
                        <x-ui.icon name="{{ $icon }}" class="h-4 w-4" />
                        <span class="flex-1">{{ $label }}</span>
                        @if ($badge)
                            <span class="rounded-full px-1.5 py-0.5 text-2xs font-bold
                                         {{ $active ? 'bg-white/20' : 'bg-brand text-white' }}">
                                @bn($badge)
                            </span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-line p-3">
                <a href="{{ route('home') }}" target="_blank"
                   class="flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium
                          text-body hover:bg-surface-2">
                    <x-ui.icon name="eye" class="h-4 w-4" /> সাইট দেখুন
                </a>
            </div>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 border-b border-line bg-surface/95 backdrop-blur">
            <div class="flex items-center gap-3 px-4 py-3">
                <button type="button" @click="sidebar = true"
                        class="rounded-md p-2 text-ink hover:bg-surface-2 lg:hidden" aria-label="মেনু">
                    <x-ui.icon name="menu" class="h-5 w-5" />
                </button>

                <h1 class="min-w-0 flex-1 truncate font-headline text-lg font-bold text-ink">
                    @yield('heading', 'অ্যাডমিন')
                </h1>

                @hasSection('actions')
                    <div class="flex shrink-0 items-center gap-2">@yield('actions')</div>
                @endif

                <x-ui.theme-toggle />

                <div x-data="{ open: false }" @click.outside="open = false" class="relative shrink-0">
                    <button type="button" @click="open = !open" class="flex items-center gap-2 rounded-full p-0.5">
                        <img src="{{ auth()->user()->avatar_url }}" alt="" width="32" height="32"
                             class="h-8 w-8 rounded-full object-cover ring-1 ring-line">
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute end-0 z-50 mt-1 w-52 overflow-hidden rounded-lg border border-line
                                bg-surface shadow-pop">
                        <div class="border-b border-line px-3 py-2">
                            <p class="truncate text-sm font-semibold text-ink">{{ auth()->user()->name }}</p>
                            <p class="text-xs text-muted">{{ auth()->user()->role->label() }}</p>
                        </div>
                        <a href="{{ route('account.index') }}"
                           class="block px-3 py-2 text-sm text-body hover:bg-surface-2">আমার প্রোফাইল</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line">
                            @csrf
                            <button type="submit" class="w-full px-3 py-2 text-start text-sm text-body hover:bg-surface-2">
                                লগআউট
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-6">
            @if (session('status'))
                <x-ui.alert type="success" class="mb-4">{{ session('status') }}</x-ui.alert>
            @endif
            @if ($errors->any() && ! $errors->hasBag('default'))
                <x-ui.alert type="error" class="mb-4">{{ $errors->first() }}</x-ui.alert>
            @endif

            @yield('content')
        </main>
    </div>
</div>
@stack('scripts')
</body>
</html>
