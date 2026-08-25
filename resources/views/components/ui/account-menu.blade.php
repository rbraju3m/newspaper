{{-- Logged-in reader menu: bookmarks, history, preferences, admin, logout. --}}
<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <button type="button" @click="open = !open"
            class="flex items-center gap-1.5 rounded-full p-0.5 hover:bg-surface-2"
            :aria-expanded="open" aria-label="আমার অ্যাকাউন্ট">
        <img src="{{ auth()->user()->avatar_url }}" alt="" width="32" height="32"
             class="h-8 w-8 rounded-full object-cover ring-1 ring-line">
        <x-ui.icon name="chevron-down" class="h-3.5 w-3.5 text-muted" />
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute right-0 z-50 mt-1 w-56 overflow-hidden rounded-lg border border-line
                bg-surface shadow-pop">
        <div class="border-b border-line px-3 py-2.5">
            <p class="truncate text-sm font-semibold text-ink">{{ auth()->user()->name }}</p>
            <p class="truncate text-xs text-muted">{{ auth()->user()->email }}</p>
        </div>

        <nav class="py-1">
            @foreach ([
                ['account.index',     'user',     'আমার প্রোফাইল'],
                ['account.bookmarks', 'bookmark', 'সংরক্ষিত খবর'],
                ['account.history',   'clock',    'পড়ার ইতিহাস'],
                ['account.preferences','settings','পছন্দসমূহ'],
            ] as [$route, $icon, $label])
                <a href="{{ route($route) }}"
                   class="flex items-center gap-2.5 px-3 py-2 text-sm text-body hover:bg-surface-2">
                    <x-ui.icon name="{{ $icon }}" class="h-4 w-4 text-muted" />
                    {{ $label }}
                </a>
            @endforeach

            @if (auth()->user()->canAccessAdmin())
                <a href="{{ route('admin.dashboard') }}"
                   class="flex items-center gap-2.5 border-t border-line px-3 py-2 text-sm
                          font-semibold text-brand hover:bg-surface-2">
                    <x-ui.icon name="newspaper" class="h-4 w-4" />
                    অ্যাডমিন প্যানেল
                </a>
            @endif
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="border-t border-line">
            @csrf
            <button type="submit"
                    class="flex w-full items-center gap-2.5 px-3 py-2 text-sm text-body hover:bg-surface-2">
                <x-ui.icon name="logout" class="h-4 w-4 text-muted" />
                লগআউট
            </button>
        </form>
    </div>
</div>
