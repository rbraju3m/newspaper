{{--
    Floating app chrome: toasts, connectivity, install prompt, update banner,
    back-to-top and the keyboard-shortcut help sheet. Everything here is
    client-side and additive — the page is fully usable without any of it.
--}}

{{-- Toasts --}}
<div class="pointer-events-none fixed inset-x-0 bottom-0 z-[70] flex flex-col items-center gap-2 p-4
            sm:inset-x-auto sm:end-0 sm:items-end"
     role="status" aria-live="polite">
    <template x-for="toast in $store.toast.items" :key="toast.id">
        <div x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-2"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-end="opacity-0 translate-y-1"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border
                    px-3.5 py-3 text-sm shadow-pop"
             :class="{
                'bg-surface border-line text-body': toast.type === 'info',
                'bg-green-50 border-green-200 text-green-900 dark:bg-green-950 dark:border-green-900 dark:text-green-100': toast.type === 'success',
                'bg-brand-50 border-brand-200 text-brand-900 dark:bg-brand-900/40 dark:border-brand-800 dark:text-brand-50': toast.type === 'error',
             }">
            <span class="min-w-0 flex-1" x-text="toast.message"></span>
            <button type="button" @click="$store.toast.dismiss(toast.id)"
                    class="shrink-0 opacity-60 hover:opacity-100" aria-label="বন্ধ করুন">
                <x-ui.icon name="close" class="h-4 w-4" />
            </button>
        </div>
    </template>
</div>

{{-- Offline bar --}}
<div x-show="!$store.pwa.online" x-cloak
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="-translate-y-full"
     class="fixed inset-x-0 top-0 z-[80] bg-ink px-4 py-2 text-center text-sm font-medium text-white"
     role="status">
    <span class="inline-flex items-center gap-2">
        <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
        আপনি অফলাইনে আছেন — আগে পড়া খবরগুলো দেখতে পারবেন
    </span>
</div>

{{-- New version available --}}
<div x-show="$store.pwa.updateReady" x-cloak
     class="fixed inset-x-0 bottom-0 z-[75] border-t border-line bg-surface p-3 shadow-pop sm:inset-x-auto
            sm:bottom-4 sm:end-4 sm:max-w-sm sm:rounded-xl sm:border">
    <p class="text-sm font-medium text-ink">নতুন সংস্করণ পাওয়া গেছে</p>
    <p class="mt-0.5 text-xs text-muted">সর্বশেষ খবর দেখতে রিফ্রেশ করুন।</p>
    <div class="mt-2.5 flex gap-2">
        <button type="button" @click="$store.pwa.applyUpdate()"
                class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-white">রিফ্রেশ</button>
        <button type="button" @click="$store.pwa.updateReady = false"
                class="rounded-lg border border-line px-4 py-1.5 text-sm font-semibold text-body">পরে</button>
    </div>
</div>

{{-- Install prompt --}}
<div x-show="$store.pwa.canInstall" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-y-4"
     class="fixed inset-x-3 bottom-3 z-[65] flex items-center gap-3 rounded-xl border border-line
            bg-surface p-3 shadow-pop sm:inset-x-auto sm:end-4 sm:max-w-sm">
    <img src="{{ asset('images/icon-192.png') }}" alt="" width="44" height="44"
         class="h-11 w-11 shrink-0 rounded-lg">
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold text-ink">অ্যাপ হিসেবে যোগ করুন</p>
        <p class="text-xs text-muted">দ্রুত খুলবে, অফলাইনেও পড়া যাবে।</p>
    </div>
    <div class="flex shrink-0 flex-col gap-1">
        <button type="button" @click="$store.pwa.install()"
                class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white">যোগ করুন</button>
        <button type="button" @click="$store.pwa.dismissInstall()"
                class="text-xs text-muted hover:text-brand">না</button>
    </div>
</div>

{{-- Back to top --}}
<div x-data="backToTop()">
    <button type="button" x-show="visible" x-cloak x-transition.opacity @click="go()"
            class="fixed bottom-4 start-4 z-[60] flex h-10 w-10 items-center justify-center rounded-full
                   border border-line bg-surface text-ink shadow-pop transition hover:text-brand"
            aria-label="উপরে যান">
        <x-ui.icon name="chevron-down" class="h-5 w-5 rotate-180" />
    </button>
</div>

{{-- Keyboard shortcut help (? to open) --}}
<div x-show="helpOpen" x-cloak @click.self="helpOpen = false"
     class="fixed inset-0 z-[90] hidden items-center justify-center bg-black/50 p-4 lg:flex"
     role="dialog" aria-modal="true" aria-label="কীবোর্ড শর্টকাট">
    <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 shadow-pop">
        <div class="flex items-center justify-between">
            <h2 class="font-headline text-lg font-bold text-ink">কীবোর্ড শর্টকাট</h2>
            <button type="button" @click="helpOpen = false" class="rounded-md p-1.5 hover:bg-surface-2">
                <x-ui.icon name="close" class="h-4 w-4" />
            </button>
        </div>

        <dl class="mt-4 space-y-2">
            @foreach ([
                ['/', 'অনুসন্ধান'], ['h', 'প্রচ্ছদ'], ['l', 'সর্বশেষ খবর'],
                ['b', 'সংরক্ষিত খবর'], ['t', 'উপরে যান'], ['d', 'ডার্ক মোড'],
                ['?', 'এই তালিকা'],
            ] as [$key, $label])
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-sm text-body">{{ $label }}</dt>
                    <dd><kbd class="lat rounded border border-line-strong bg-surface-2 px-2 py-0.5
                                    text-xs font-semibold text-ink">{{ $key }}</kbd></dd>
                </div>
            @endforeach
        </dl>
    </div>
</div>
