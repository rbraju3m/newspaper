@props(['article'])

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}
     x-data="shareBar(
        {{ Js::from($article->url) }},
        {{ Js::from($article->title) }},
        {{ Js::from(route('api.share', $article)) }}
     )">

    <span class="mr-1 text-sm font-semibold text-ink">শেয়ার:</span>

    {{-- Native share sheet where the platform has one; the per-network buttons
         stay for desktop, where navigator.share is mostly absent. --}}
    <button type="button" x-show="canNative" x-cloak @click="native()"
            class="flex items-center gap-1.5 rounded-lg bg-brand px-3 py-1.5 text-sm
                   font-semibold text-white hover:bg-brand-700">
        <x-ui.icon name="share" class="h-3.5 w-3.5" /> শেয়ার
    </button>

    @foreach ([
        ['facebook', 'ফেসবুক', '#1877F2'],
        ['whatsapp', 'হোয়াটসঅ্যাপ', '#25D366'],
        ['twitter', 'এক্স', '#0F1419'],
        ['telegram', 'টেলিগ্রাম', '#229ED9'],
    ] as [$network, $label, $color])
        <button type="button" @click="open('{{ $network }}')" aria-label="{{ $label }}"
                class="flex h-8 w-8 items-center justify-center rounded-lg border border-line
                       text-body transition hover:text-white"
                onmouseover="this.style.backgroundColor='{{ $color }}';this.style.borderColor='{{ $color }}'"
                onmouseout="this.style.backgroundColor='';this.style.borderColor=''">
            <x-ui.icon name="{{ $network }}" class="h-4 w-4" />
        </button>
    @endforeach

    <button type="button" @click="copy()" :aria-label="copied ? 'কপি হয়েছে' : 'লিংক কপি করুন'"
            class="flex h-8 items-center gap-1.5 rounded-lg border border-line px-2.5 text-xs
                   font-medium text-body transition hover:border-brand hover:text-brand">
        <x-ui.icon name="copy" class="h-3.5 w-3.5" x-show="!copied" />
        <x-ui.icon name="check" class="h-3.5 w-3.5 text-green-600" x-show="copied" x-cloak />
        <span x-text="copied ? 'কপি হয়েছে' : 'লিংক'"></span>
    </button>

    <button type="button" @click="print()" aria-label="প্রিন্ট করুন"
            class="hidden h-8 w-8 items-center justify-center rounded-lg border border-line
                   text-body transition hover:border-brand hover:text-brand lg:flex">
        <x-ui.icon name="print" class="h-4 w-4" />
    </button>
</div>
