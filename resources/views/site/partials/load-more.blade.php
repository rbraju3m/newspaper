@props(['paginator'])

{{-- Progressive enhancement: a real <a> that works without JS and is
     crawlable, upgraded by Alpine into infinite scroll. --}}
@if ($paginator->hasMorePages())
    <div class="mt-8 flex justify-center">
        <a href="{{ $paginator->nextPageUrl() }}"
           x-intersect.once="load()"
           @click.prevent="load()"
           :class="loading && 'pointer-events-none opacity-60'"
           class="rounded-lg border border-line-strong bg-surface px-6 py-2.5 text-sm
                  font-semibold text-ink transition hover:border-brand hover:text-brand"
           x-show="!done">
            <span x-show="!loading">আরও খবর</span>
            <span x-show="loading" x-cloak>লোড হচ্ছে…</span>
        </a>
    </div>

    <p x-show="failed" x-cloak class="mt-4 text-center text-sm text-brand">
        লোড করা যায়নি। <button type="button" @click="load()" class="underline">আবার চেষ্টা করুন</button>
    </p>
@endif
