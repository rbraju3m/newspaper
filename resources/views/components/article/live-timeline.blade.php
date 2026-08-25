@props(['article'])

@php
    $entries = $article->liveEntries()->with('author:id,name')->limit(40)->get();
    $latestId = (int) $entries->max('id');
    $keyPoints = $entries->where('is_key', true)->take(5);
@endphp

<section x-data="liveBlog({{ Js::from(route('api.live', $article)) }}, {{ $latestId }})"
         class="mt-6">

    <div class="mb-4 flex items-center justify-between gap-3 border-b-2 border-brand pb-2">
        <h2 class="flex items-center gap-2 font-headline text-xl font-bold text-ink">
            <span class="relative flex h-2.5 w-2.5">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand opacity-75"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-brand"></span>
            </span>
            লাইভ আপডেট
        </h2>
        <span class="text-xs text-muted">
            স্বয়ংক্রিয়ভাবে হালনাগাদ হচ্ছে
        </span>
    </div>

    {{-- Key points summary — readers arriving late need the shape of the story
         before the minute-by-minute detail. --}}
    @if ($keyPoints->isNotEmpty())
        <div class="mb-5 rounded-xl border border-line bg-surface-2 p-4">
            <h3 class="text-sm font-bold text-ink">এক নজরে</h3>
            <ul class="mt-2 space-y-1.5">
                @foreach ($keyPoints as $point)
                    <li class="flex gap-2 text-sm text-body">
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand"></span>
                        <span>{{ $point->headline ?: Str::limit(strip_tags($point->body), 110) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- New-updates pill --}}
    <div class="sticky top-16 z-20 mb-3 flex justify-center" x-show="unseen > 0" x-cloak x-transition>
        <button type="button" @click="reveal()"
                class="rounded-full bg-brand px-4 py-1.5 text-sm font-semibold text-white shadow-pop">
            <span class="lat" x-text="unseen"></span> টি নতুন আপডেট
        </button>
    </div>

    <ol x-ref="timeline" class="relative space-y-5 border-s-2 border-line ps-5">
        {{-- Entries that arrived after page load --}}
        <template x-for="entry in entries" :key="entry.id">
            <li class="relative" x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 -translate-y-2">
                <span class="absolute -start-[27px] top-1.5 h-3 w-3 rounded-full border-2 border-canvas bg-brand"></span>
                <div class="rounded-xl border border-brand/40 bg-surface p-4">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="rounded bg-brand px-1.5 py-0.5 font-bold text-white">নতুন</span>
                        <time class="text-muted" x-text="entry.time"></time>
                        <template x-if="entry.author">
                            <span class="text-muted" x-text="'— ' + entry.author"></span>
                        </template>
                    </div>
                    <template x-if="entry.headline">
                        <h3 class="mt-1.5 font-headline text-base font-bold text-ink" x-text="entry.headline"></h3>
                    </template>
                    <div class="prose-editorial mt-1.5 text-sm text-body" x-html="entry.body"></div>
                    <template x-if="entry.image">
                        <img :src="entry.image" alt="" loading="lazy" class="mt-3 w-full rounded-lg">
                    </template>
                </div>
            </li>
        </template>

        {{-- Server-rendered entries --}}
        @forelse ($entries as $entry)
            <li class="relative" id="live-{{ $entry->id }}">
                <span @class([
                    'absolute -start-[27px] top-1.5 h-3 w-3 rounded-full border-2 border-canvas',
                    'bg-brand' => $entry->is_pinned || $loop->first,
                    'bg-line-strong' => ! $entry->is_pinned && ! $loop->first,
                ])></span>

                <div @class([
                    'rounded-xl border bg-surface p-4',
                    'border-brand/40' => $entry->is_pinned,
                    'border-line' => ! $entry->is_pinned,
                ])>
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        @if ($entry->is_pinned)
                            <span class="rounded bg-brand px-1.5 py-0.5 font-bold text-white">পিন করা</span>
                        @endif
                        <time class="font-medium text-muted"
                              datetime="{{ $entry->published_at->toIso8601String() }}">
                            {{ $entry->time_label }}
                        </time>
                        @if ($entry->author)
                            <span class="text-muted">— {{ $entry->author->name }}</span>
                        @endif
                    </div>

                    @if ($entry->headline)
                        <h3 class="mt-1.5 font-headline text-base font-bold text-ink">{{ $entry->headline }}</h3>
                    @endif

                    <div class="prose-editorial mt-1.5 text-sm text-body">{!! $entry->body !!}</div>

                    @if ($entry->image)
                        <img src="{{ asset('storage/'.$entry->image) }}" alt="" loading="lazy"
                             class="mt-3 w-full rounded-lg">
                    @endif

                    @if ($entry->embed_url)
                        <x-article.video-embed :url="$entry->embed_url" class="mt-3" />
                    @endif
                </div>
            </li>
        @empty
            <li class="text-sm text-muted">এখনো কোনো আপডেট যোগ করা হয়নি।</li>
        @endforelse
    </ol>
</section>
