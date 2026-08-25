{{-- Full category tree. On desktop it drops from the nav; on mobile it becomes
     a full-screen sheet, which is where the reference sites fall down worst. --}}
<div x-show="mega" x-cloak class="fixed inset-0 z-50 lg:absolute lg:inset-x-0 lg:inset-y-auto">
    <div x-show="mega" x-transition.opacity.duration.200ms @click="mega = false"
         class="fixed inset-0 bg-black/50 lg:bg-black/30"></div>

    <div x-show="mega"
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="opacity-0 -translate-y-3"
         x-transition:enter-end="opacity-100 translate-y-0"
         class="relative ml-auto flex h-full w-full max-w-sm flex-col bg-surface
                lg:mx-auto lg:h-auto lg:max-h-[75vh] lg:w-full lg:max-w-none lg:overflow-y-auto
                lg:border-b lg:border-line lg:shadow-pop">

        <div class="flex items-center justify-between border-b border-line px-4 py-3 lg:hidden">
            <x-ui.logo class="h-8 w-auto" />
            <button type="button" @click="mega = false" class="rounded-md p-2 hover:bg-surface-2"
                    aria-label="মেনু বন্ধ করুন">
                <x-ui.icon name="close" class="h-5 w-5" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="mx-auto max-w-site px-4 py-5">
                <div class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ($allCategories as $category)
                        <div>
                            <a href="{{ route('category.show', $category->path) }}"
                               class="flex items-center gap-2 border-b-2 pb-1.5 font-headline text-base
                                      font-bold text-ink hover:text-brand"
                               style="border-color: {{ $category->color }}">
                                {{ $category->name }}
                            </a>
                            @if ($category->children->isNotEmpty())
                                <ul class="mt-2 space-y-1">
                                    @foreach ($category->children as $child)
                                        <li>
                                            <a href="{{ route('category.show', $child->path) }}"
                                               class="block py-0.5 text-sm text-body hover:text-brand">
                                                {{ $child->name }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Utility links, duplicated here because on mobile the top bar is hidden --}}
                <div class="mt-6 grid grid-cols-2 gap-2 border-t border-line pt-5 sm:grid-cols-4">
                    @foreach ([
                        ['epaper.index', 'newspaper', 'ই-পেপার'],
                        ['archive',      'calendar',  'আর্কাইভ'],
                        ['video.index',  'play',      'ভিডিও'],
                        ['photo.index',  'camera',    'ফটো'],
                    ] as [$route, $icon, $label])
                        <a href="{{ route($route) }}"
                           class="flex items-center gap-2 rounded-lg border border-line px-3 py-2.5
                                  text-sm font-semibold text-ink hover:border-brand hover:text-brand">
                            <x-ui.icon name="{{ $icon }}" class="h-4 w-4" />
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                {{-- Account actions — mobile only; desktop has them in the nav bar --}}
                <div class="mt-5 flex items-center gap-2 border-t border-line pt-5 lg:hidden">
                    @auth
                        <a href="{{ route('account.index') }}"
                           class="flex flex-1 items-center justify-center gap-2 rounded-lg bg-surface-2
                                  px-4 py-2.5 text-sm font-semibold text-ink">
                            <x-ui.icon name="user" class="h-4 w-4" /> আমার অ্যাকাউন্ট
                        </a>
                    @else
                        <a href="{{ route('login') }}"
                           class="flex-1 rounded-lg border border-line px-4 py-2.5 text-center text-sm
                                  font-semibold text-ink">লগইন</a>
                        <a href="{{ route('register') }}"
                           class="flex-1 rounded-lg bg-brand px-4 py-2.5 text-center text-sm
                                  font-semibold text-white">নিবন্ধন</a>
                    @endauth
                    <x-ui.theme-toggle />
                </div>
            </div>
        </div>
    </div>
</div>
