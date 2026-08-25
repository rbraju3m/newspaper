{{-- Light / dark / follow-system. None of the reference sites offer this. --}}
<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <button type="button" @click="open = !open"
            class="rounded-md p-2 text-ink hover:bg-surface-2"
            :aria-expanded="open" aria-label="থিম পরিবর্তন">
        <x-ui.icon name="sun" class="h-4.5 w-4.5 dark:hidden" />
        <x-ui.icon name="moon" class="hidden h-4.5 w-4.5 dark:block" />
    </button>

    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute right-0 z-50 mt-1 w-40 overflow-hidden rounded-lg border border-line
                bg-surface py-1 shadow-pop">
        @foreach ([['light','sun','লাইট'], ['dark','moon','ডার্ক'], ['system','monitor','সিস্টেম']] as [$mode, $icon, $label])
            <button type="button" @click="$store.theme.set('{{ $mode }}'); open = false"
                    class="flex w-full items-center gap-2.5 px-3 py-2 text-sm text-body hover:bg-surface-2"
                    :class="$store.theme.mode === '{{ $mode }}' && 'text-brand font-semibold'">
                <x-ui.icon name="{{ $icon }}" class="h-4 w-4" />
                {{ $label }}
                <x-ui.icon name="check" class="ml-auto h-4 w-4"
                           x-show="$store.theme.mode === '{{ $mode }}'" x-cloak />
            </button>
        @endforeach
    </div>
</div>
