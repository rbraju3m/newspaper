@extends('layouts.site')

@section('title', ($term ? $term.' — অনুসন্ধান' : 'অনুসন্ধান').' — '.config('site.name_bn'))
@push('head')<meta name="robots" content="noindex, follow">@endpush

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
        <h1 class="font-headline text-2xl font-bold text-ink lg:text-3xl">অনুসন্ধান</h1>

        <form action="{{ route('search') }}" method="GET"
              class="mt-4 grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label for="q" class="mb-1 block text-xs font-semibold text-ink">খুঁজুন</label>
                <input id="q" type="search" name="q" value="{{ $term }}" required
                       placeholder="শব্দ বা বাক্য লিখুন"
                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm
                              text-ink outline-none focus:border-brand">
            </div>

            <div>
                <label for="f-cat" class="mb-1 block text-xs font-semibold text-ink">বিভাগ</label>
                <select id="f-cat" name="category"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    <option value="">সব বিভাগ</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->slug }}" @selected(($filters['category'] ?? null) === $c->slug)>
                            {{ $c->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="f-from" class="mb-1 block text-xs font-semibold text-ink">তারিখ থেকে</label>
                <input id="f-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink lat">
            </div>

            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label for="f-sort" class="mb-1 block text-xs font-semibold text-ink">সাজান</label>
                    <select id="f-sort" name="sort"
                            class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                        @foreach (['relevance' => 'প্রাসঙ্গিকতা', 'newest' => 'নতুন', 'popular' => 'জনপ্রিয়'] as $v => $l)
                            <option value="{{ $v }}" @selected(($filters['sort'] ?? 'relevance') === $v)>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit"
                        class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    খুঁজুন
                </button>
            </div>
        </form>

        @if ($articles)
            <p class="mt-6 text-sm text-muted">
                “<span class="font-semibold text-ink">{{ $term }}</span>” — মোট
                <span class="font-semibold text-ink">@bn($articles->total())</span> টি ফলাফল
            </p>

            <div class="mt-4 grid gap-x-8 gap-y-5 lg:grid-cols-2">
                @include('site.partials.article-list-items')
            </div>

            @if ($articles->isEmpty())
                <x-ui.empty-state icon="search" title="কিছু পাওয়া যায়নি"
                                  message="বানান পরীক্ষা করুন বা অন্য শব্দ দিয়ে চেষ্টা করুন।" />
            @endif

            <div class="mt-8">{{ $articles->links() }}</div>
        @else
            <x-ui.empty-state icon="search" title="কী খুঁজছেন?"
                              message="উপরের বাক্সে শব্দ লিখে অনুসন্ধান করুন।" class="mt-6" />
        @endif
    </div>
@endsection
