@props(['title', 'href' => null, 'color' => null, 'subtitle' => null])

{{-- Coloured rule + heading + "more" link. Repeated above every block, so it
     lives in one place and every section reads identically. --}}
<div {{ $attributes->merge(['class' => 'mb-4 flex items-end justify-between gap-4 border-b-2 border-line pb-2']) }}
     @if ($color) style="border-bottom-color: {{ $color }}" @endif>
    <div class="min-w-0">
        <h2 class="font-headline text-xl font-bold text-ink lg:text-2xl">{{ $title }}</h2>
        @if ($subtitle)
            <p class="mt-0.5 truncate text-sm text-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @if ($href)
        <a href="{{ $href }}"
           class="flex shrink-0 items-center gap-1 pb-1 text-sm font-semibold text-muted
                  transition hover:text-brand">
            আরও দেখুন
            <x-ui.icon name="chevron-right" class="h-3.5 w-3.5" />
        </a>
    @endif
</div>
