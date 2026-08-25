@props(['type' => 'info', 'title' => null])

@php
    $styles = [
        'success' => ['bg-green-50 border-green-200 text-green-900 dark:bg-green-950/40 dark:border-green-900 dark:text-green-200', 'check'],
        'error' => ['bg-brand-50 border-brand-200 text-brand-900 dark:bg-brand-900/25 dark:border-brand-800 dark:text-brand-100', 'close'],
        'info' => ['bg-blue-50 border-blue-200 text-blue-900 dark:bg-blue-950/40 dark:border-blue-900 dark:text-blue-200', 'mail'],
        'warning' => ['bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-900 dark:text-amber-200', 'clock'],
    ][$type] ?? null;
@endphp

<div role="{{ $type === 'error' ? 'alert' : 'status' }}"
     {{ $attributes->merge(['class' => 'flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-sm '.$styles[0]]) }}>
    <x-ui.icon :name="$styles[1]" class="mt-0.5 h-4 w-4 shrink-0" />
    <div class="min-w-0">
        @if ($title)<p class="font-semibold">{{ $title }}</p>@endif
        <div class="{{ $title ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>
</div>
