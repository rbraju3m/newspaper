@props(['article'])

{{-- Vertical share rail pinned beside the article column on wide screens.
     Hidden below xl, where the horizontal bar above the body already serves.
     aria-hidden because it duplicates that bar for screen readers. --}}
<div {{ $attributes->merge(['class' => 'pointer-events-none absolute -start-16 top-0 hidden h-full xl:block']) }}
     x-data="shareBar(
        {{ Js::from($article->url) }},
        {{ Js::from($article->title) }},
        {{ Js::from(route('api.share', $article)) }}
     )"
     aria-hidden="true">
    <div class="pointer-events-auto sticky top-24 flex flex-col items-center gap-1.5">
        <span class="mb-1 text-2xs font-semibold uppercase tracking-wide text-muted">শেয়ার</span>

        @foreach ([
            ['facebook', '#1877F2'],
            ['whatsapp', '#25D366'],
            ['twitter', '#0F1419'],
            ['telegram', '#229ED9'],
        ] as [$network, $colour])
            <button type="button" @click="open('{{ $network }}')" tabindex="-1"
                    class="flex h-9 w-9 items-center justify-center rounded-full border border-line
                           bg-surface text-body transition hover:text-white"
                    onmouseover="this.style.backgroundColor='{{ $colour }}';this.style.borderColor='{{ $colour }}'"
                    onmouseout="this.style.backgroundColor='';this.style.borderColor=''">
                <x-ui.icon name="{{ $network }}" class="h-4 w-4" />
            </button>
        @endforeach

        <button type="button" @click="copy(); $store.toast.success('লিংক কপি হয়েছে')" tabindex="-1"
                class="flex h-9 w-9 items-center justify-center rounded-full border border-line
                       bg-surface text-body transition hover:border-brand hover:text-brand">
            <x-ui.icon name="copy" class="h-4 w-4" x-show="!copied" />
            <x-ui.icon name="check" class="h-4 w-4 text-green-600" x-show="copied" x-cloak />
        </button>

        <span class="my-1 h-px w-6 bg-line"></span>

        <button type="button"
                @click="$store.reader.toggleBookmark({{ $article->id }}, '{{ route('account.bookmarks.toggle', $article) }}')"
                tabindex="-1"
                class="flex h-9 w-9 items-center justify-center rounded-full border border-line
                       bg-surface text-body transition hover:border-brand hover:text-brand"
                :class="$store.reader.has({{ $article->id }}) && 'border-brand text-brand'">
            <x-ui.icon name="bookmark" class="h-4 w-4" />
        </button>
    </div>
</div>
