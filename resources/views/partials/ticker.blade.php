@props(['items'])

{{-- Breaking-news ticker. Refreshes itself so a breaking story surfaces without
     a page reload; pauses on hover and when the tab is hidden. --}}
<div class="border-b border-line bg-brand text-white"
     x-data="ticker(
        {{ Js::from($items->map->tickerPayload()) }},
        '{{ route('api.breaking') }}'
     )"
     @mouseenter="paused = true" @mouseleave="paused = false"
     @focusin="paused = true" @focusout="paused = false">

    <div class="mx-auto flex h-11 max-w-site items-center gap-3 px-4">
        <span class="flex shrink-0 items-center gap-1.5 rounded-sm bg-white/15 px-2 py-1
                     text-2xs font-bold uppercase tracking-wide">
            <span class="relative flex h-1.5 w-1.5">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-white"></span>
            </span>
            ব্রেকিং
        </span>

        <div class="relative min-w-0 flex-1" aria-live="polite" aria-atomic="true">
            <template x-for="(item, i) in items" :key="item.id">
                <a :href="item.url" x-show="i === index" x-cloak
                   x-transition:enter="transition ease-out duration-300"
                   x-transition:enter-start="opacity-0 translate-y-1.5"
                   x-transition:enter-end="opacity-100 translate-y-0"
                   class="block truncate text-sm font-semibold hover:underline"
                   x-text="item.title"></a>
            </template>
        </div>

        <div class="flex shrink-0 items-center gap-0.5" x-show="items.length > 1">
            <button type="button" @click="prev()" class="rounded p-1 hover:bg-white/15" aria-label="পূর্ববর্তী">
                <x-ui.icon name="chevron-left" class="h-3.5 w-3.5" />
            </button>
            <button type="button" @click="next()" class="rounded p-1 hover:bg-white/15" aria-label="পরবর্তী">
                <x-ui.icon name="chevron-right" class="h-3.5 w-3.5" />
            </button>
        </div>
    </div>
</div>
