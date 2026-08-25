@props(['variant' => 'card', 'count' => 1])

{{-- Placeholder shown while infinite scroll fetches. Matches the real card's
     box exactly, so the page does not jump when content replaces it. --}}
@for ($i = 0; $i < $count; $i++)
    @if ($variant === 'card')
        <div {{ $attributes->merge(['class' => 'animate-pulse']) }} aria-hidden="true">
            <div class="aspect-[16/9] rounded-md bg-surface-2"></div>
            <div class="mt-2 h-2.5 w-16 rounded bg-surface-2"></div>
            <div class="mt-2 h-4 w-full rounded bg-surface-2"></div>
            <div class="mt-1.5 h-4 w-4/5 rounded bg-surface-2"></div>
            <div class="mt-2 h-2.5 w-24 rounded bg-surface-2"></div>
        </div>
    @elseif ($variant === 'list')
        <div {{ $attributes->merge(['class' => 'flex animate-pulse items-start gap-3']) }} aria-hidden="true">
            <div class="aspect-[4/3] w-24 shrink-0 rounded-md bg-surface-2 sm:w-28"></div>
            <div class="min-w-0 flex-1">
                <div class="h-4 w-full rounded bg-surface-2"></div>
                <div class="mt-1.5 h-4 w-3/4 rounded bg-surface-2"></div>
                <div class="mt-2 h-2.5 w-20 rounded bg-surface-2"></div>
            </div>
        </div>
    @endif
@endfor
