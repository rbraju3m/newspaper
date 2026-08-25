@props(['title', 'message' => null, 'icon' => 'newspaper'])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-xl border border-dashed border-line px-6 py-16 text-center']) }}>
    <x-ui.icon :name="$icon" class="h-10 w-10 text-muted/40" />
    <h2 class="mt-3 font-headline text-lg font-semibold text-ink">{{ $title }}</h2>
    @if ($message)
        <p class="mt-1 max-w-sm text-sm text-muted">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
