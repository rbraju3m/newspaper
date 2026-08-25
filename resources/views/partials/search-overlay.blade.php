{{-- Full-width search sheet, opened from either the mobile or desktop button. --}}
<div x-show="search" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 -translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     class="absolute inset-x-0 z-40 border-b border-line bg-surface shadow-pop"
     @click.outside="search = false">
    <div class="mx-auto max-w-site px-4 py-5">
        <form action="{{ route('search') }}" method="GET" role="search">
            <label for="site-search" class="sr-only">খবর খুঁজুন</label>
            <div class="flex items-center gap-2 rounded-lg border border-line-strong bg-canvas px-3
                        focus-within:border-brand">
                <x-ui.icon name="search" class="h-5 w-5 text-muted" />
                <input id="site-search" type="search" name="q" value="{{ request('q') }}"
                       x-ref="q" x-effect="search && $nextTick(() => $refs.q.focus())"
                       placeholder="খবর, বিষয় বা লেখক খুঁজুন…" autocomplete="off"
                       class="w-full bg-transparent py-3 text-base text-ink outline-none placeholder:text-muted">
                <button type="submit"
                        class="shrink-0 rounded-md bg-brand px-4 py-1.5 text-sm font-semibold text-white
                               hover:bg-brand-700">
                    খুঁজুন
                </button>
                <button type="button" @click="search = false"
                        class="shrink-0 rounded-md p-1.5 text-muted hover:bg-surface-2" aria-label="বন্ধ করুন">
                    <x-ui.icon name="close" class="h-4 w-4" />
                </button>
            </div>
        </form>

        @if ($trendingTopics->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs text-muted">জনপ্রিয়:</span>
                @foreach ($trendingTopics->take(6) as $topic)
                    <a href="{{ route('search') }}?q={{ urlencode($topic->name) }}"
                       class="rounded-full bg-surface-2 px-2.5 py-1 text-xs text-body hover:text-brand">
                        {{ $topic->name }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
