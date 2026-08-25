{{--
    Site header.

    Composed of four bands, mirroring the structure shared by every reference
    paper but with the clutter stripped out:
      1. utility bar  — Bangla date, e-paper, archive, language, socials
      2. masthead     — logo + reserved leaderboard slot (fixed height = no CLS)
      3. nav          — sticky category bar, overflow menu, search, theme, account
      4. ticker       — breaking news, only rendered when something is breaking
--}}
<header x-data="{ mega: false, search: false }" @keydown.escape.window="mega = false; search = false">

    {{-- 1 ── Utility bar ────────────────────────────────────────────────── --}}
    <div class="hidden border-b border-line bg-surface lg:block">
        <div class="mx-auto flex h-9 max-w-site items-center justify-between px-4 text-xs">
            <p class="text-muted">@bnfulldate()</p>

            <div class="flex items-center gap-4">
                <a href="{{ route('epaper.index') }}" class="font-medium text-body hover:text-brand">ই-পেপার</a>
                <span class="h-3 w-px bg-line"></span>
                <a href="{{ route('archive') }}" class="font-medium text-body hover:text-brand">আর্কাইভ</a>
                <span class="h-3 w-px bg-line"></span>
                <a href="{{ route('live') }}" class="flex items-center gap-1.5 font-medium text-body hover:text-brand">
                    <span class="relative flex h-1.5 w-1.5">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand opacity-75"></span>
                        <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-brand"></span>
                    </span>
                    লাইভ
                </a>
                <span class="h-3 w-px bg-line"></span>

                <div class="flex items-center gap-2.5">
                    @foreach (config('site.social') as $name => $url)
                        @if ($url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                               aria-label="{{ ucfirst($name) }}" class="text-muted transition hover:text-brand">
                                <x-ui.icon :name="$name" class="h-3.5 w-3.5" />
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- 2 ── Masthead ───────────────────────────────────────────────────── --}}
    <div class="border-b border-line bg-surface">
        <div class="mx-auto flex max-w-site items-center gap-6 px-4 py-3 lg:py-5">
            <a href="{{ route('home') }}" class="shrink-0" aria-label="{{ config('app.name') }} — প্রচ্ছদ">
                <x-ui.logo class="h-9 w-auto lg:h-14" />
            </a>

            {{-- Reserved 728×90 box. Fixed height keeps CLS at zero whether or
                 not an ad is served — the single biggest flaw on the sites we
                 benchmarked. --}}
            <x-ui.ad-slot position="header_leaderboard" class="ml-auto hidden h-[90px] w-[728px] lg:grid" />

            <button type="button" @click="search = !search"
                    class="ml-auto rounded-md p-2 text-ink hover:bg-surface-2 lg:hidden"
                    aria-label="অনুসন্ধান">
                <x-ui.icon name="search" class="h-5 w-5" />
            </button>
            <button type="button" @click="mega = true"
                    class="rounded-md p-2 text-ink hover:bg-surface-2 lg:hidden"
                    aria-label="মেনু" :aria-expanded="mega">
                <x-ui.icon name="menu" class="h-5 w-5" />
            </button>
        </div>
    </div>

    {{-- 3 ── Sticky category nav ────────────────────────────────────────── --}}
    <nav class="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur
                supports-[backdrop-filter]:bg-surface/80"
         aria-label="প্রধান মেনু">
        <div class="mx-auto flex max-w-site items-center gap-1 px-4">

            <button type="button" @click="mega = true"
                    class="hidden shrink-0 items-center gap-2 py-3 pr-3 text-sm font-semibold text-ink
                           hover:text-brand lg:flex"
                    aria-label="সব বিভাগ" :aria-expanded="mega">
                <x-ui.icon name="menu" class="h-4 w-4" />
                <span>সব বিভাগ</span>
            </button>

            {{-- Horizontally scrollable on mobile, wrapping never allowed --}}
            <ul class="no-scrollbar flex flex-1 items-center gap-0.5 overflow-x-auto lg:gap-0">
                @foreach ($navCategories as $category)
                    <li class="shrink-0">
                        <a href="{{ route('category.show', $category->path) }}"
                           @class([
                               'relative block whitespace-nowrap px-3 py-3 text-sm font-semibold transition',
                               'text-brand' => $activeCategoryId === $category->id,
                               'text-ink hover:text-brand' => $activeCategoryId !== $category->id,
                           ])
                           @if ($activeCategoryId === $category->id) aria-current="page" @endif>
                            {{ $category->name }}
                            @if ($activeCategoryId === $category->id)
                                <span class="absolute inset-x-2 bottom-0 h-0.5 rounded-full"
                                      style="background: {{ $category->color }}"></span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="hidden shrink-0 items-center gap-1 pl-2 lg:flex">
                <button type="button" @click="search = !search"
                        class="rounded-md p-2 text-ink hover:bg-surface-2" aria-label="অনুসন্ধান">
                    <x-ui.icon name="search" class="h-4.5 w-4.5" />
                </button>

                <x-ui.theme-toggle />

                @auth
                    <x-ui.account-menu />
                @else
                    <a href="{{ route('login') }}"
                       class="rounded-md px-3 py-1.5 text-sm font-semibold text-ink hover:bg-surface-2">
                        লগইন
                    </a>
                    <a href="{{ route('register') }}"
                       class="rounded-md bg-brand px-3.5 py-1.5 text-sm font-semibold text-white
                              transition hover:bg-brand-700">
                        নিবন্ধন
                    </a>
                @endauth
            </div>
        </div>

        {{-- Trending topic chips — borrowed from Ittefaq / Naya Diganta --}}
        @if ($trendingTopics->isNotEmpty())
            <div class="border-t border-line/70 bg-surface-2/50">
                <div class="no-scrollbar mx-auto flex max-w-site items-center gap-2 overflow-x-auto px-4 py-2">
                    <span class="shrink-0 text-2xs font-bold uppercase tracking-wide text-muted">ট্রেন্ডিং</span>
                    @foreach ($trendingTopics as $topic)
                        <a href="{{ route('topic.show', $topic->slug) }}"
                           class="shrink-0 rounded-full border border-line bg-surface px-3 py-1 text-xs
                                  font-medium text-body transition hover:border-brand hover:text-brand">
                            {{ $topic->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </nav>

    {{-- 4 ── Breaking ticker ────────────────────────────────────────────── --}}
    @includeWhen($breakingNews->isNotEmpty(), 'partials.ticker', ['items' => $breakingNews])

    {{-- Search overlay --}}
    @include('partials.search-overlay')

    {{-- Mega menu --}}
    @include('partials.mega-menu')
</header>
