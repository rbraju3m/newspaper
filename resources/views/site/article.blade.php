@extends('layouts.site')

@section('title', ($article->meta_title ?: $article->title).' — '.config('site.name_bn'))
@section('description', $article->meta_description ?: $article->excerpt)
@section('canonical', $article->url)
@section('og_type', 'article')
@section('og_title', $article->title)
@section('og_description', $article->excerpt)
@section('og_image', $article->image_url ?: asset('images/og-default.jpg'))

@push('head')
    <meta property="article:published_time" content="{{ $article->published_at?->toIso8601String() }}">
    <meta property="article:modified_time" content="{{ $article->updated_at?->toIso8601String() }}">
    <meta property="article:section" content="{{ $article->category?->name }}">
    @foreach ($article->tags as $tag)
        <meta property="article:tag" content="{{ $tag->name }}">
    @endforeach
@endpush

@push('schema')
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $article->url],
    'headline' => Str::limit($article->title, 110, ''),
    'description' => $article->excerpt,
    'image' => $article->image_url ? [$article->image_url] : null,
    'datePublished' => $article->published_at?->toIso8601String(),
    'dateModified' => $article->updated_at?->toIso8601String(),
    'articleSection' => $article->category?->name,
    'inLanguage' => $article->locale,
    'wordCount' => str_word_count(strip_tags((string) $article->body)),
    'author' => $article->author ? [
        '@type' => 'Person',
        'name' => $article->author->name,
        'url' => route('author.show', $article->author),
    ] : null,
    'publisher' => [
        '@type' => 'Organization',
        'name' => config('site.name_bn'),
        'logo' => ['@type' => 'ImageObject', 'url' => asset('images/logo.png')],
    ],
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endpush

@section('content')
    {{-- Reading-progress bar. Doubles as the source for reading_history.progress. --}}
    <div x-data="readingTracker(
            {{ Js::from(auth()->check() ? route('history.track', $article) : null) }},
            {{ Js::from(auth()->check()) }}
         )"
         @scroll.window.passive="
            const el = $refs.body;
            if (!el) return;
            const start = el.offsetTop;
            const total = el.offsetHeight - window.innerHeight * 0.4;
            report(Math.min(100, Math.max(0, ((window.scrollY - start) / total) * 100)));
         "
         class="relative">

        <div class="fixed inset-x-0 top-0 z-40 h-0.5 bg-transparent" aria-hidden="true">
            <div class="h-full bg-brand transition-[width] duration-150"
                 :style="`width: ${progress}%`"></div>
        </div>

        <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
            @include('site.partials.breadcrumb', [
                'items' => [
                    ['label' => $article->category->name, 'url' => $article->category->url()],
                    ['label' => Str::limit($article->title, 40)],
                ],
            ])

            <div class="grid gap-8 lg:grid-cols-12 lg:gap-10">
                <article class="relative lg:col-span-8">

                    <x-article.share-rail :article="$article" />

                    <header>
                        @if ($article->kicker)
                            <span class="text-sm font-bold uppercase tracking-wide"
                                  style="color: {{ $article->category->color }}">{{ $article->kicker }}</span>
                        @endif

                        <h1 class="mt-1 font-headline text-3xl font-bold leading-tight text-ink lg:text-5xl">
                            {{ $article->title }}
                        </h1>

                        @if ($article->subtitle)
                            <p class="mt-3 font-headline text-lg text-body lg:text-2xl">{{ $article->subtitle }}</p>
                        @endif

                        {{-- Byline row: author, dateline, timestamp, reading time --}}
                        <div class="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3 border-y border-line py-3">
                            @if ($article->author)
                                <a href="{{ route('author.show', $article->author) }}"
                                   class="flex items-center gap-2.5 group">
                                    <img src="{{ $article->author->avatar_url }}" alt=""
                                         width="40" height="40"
                                         class="h-10 w-10 rounded-full object-cover ring-1 ring-line">
                                    <span>
                                        <span class="block text-sm font-semibold text-ink group-hover:text-brand">
                                            {{ $article->author->name }}
                                        </span>
                                        @if ($article->dateline)
                                            <span class="block text-xs text-muted">{{ $article->dateline }}</span>
                                        @endif
                                    </span>
                                </a>
                            @elseif ($article->dateline)
                                <span class="text-sm font-semibold text-ink">{{ $article->dateline }}</span>
                            @endif

                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                                <time datetime="{{ $article->published_at?->toIso8601String() }}">
                                    প্রকাশ: @bndate($article->published_at), @bntime($article->published_at)
                                </time>
                                <span class="flex items-center gap-1">
                                    <x-ui.icon name="clock" class="h-3.5 w-3.5" />
                                    @bn($article->reading_time) মিনিট পড়া
                                </span>
                                <span class="flex items-center gap-1">
                                    <x-ui.icon name="eye" class="h-3.5 w-3.5" />
                                    @bncount($article->views)
                                </span>
                            </div>

                            {{-- Reading controls — font size and bookmark. No
                                 reference site offers either, despite very dense
                                 Bangla type. --}}
                            <div class="ml-auto flex items-center gap-1">
                                <button type="button" @click="$store.reader.smaller()"
                                        :disabled="!$store.reader.canShrink"
                                        class="rounded-md border border-line px-2 py-1 text-xs font-bold
                                               text-body hover:border-brand hover:text-brand disabled:opacity-40"
                                        aria-label="ফন্ট ছোট করুন">অ−</button>
                                <button type="button" @click="$store.reader.bigger()"
                                        :disabled="!$store.reader.canGrow"
                                        class="rounded-md border border-line px-2 py-1 text-sm font-bold
                                               text-body hover:border-brand hover:text-brand disabled:opacity-40"
                                        aria-label="ফন্ট বড় করুন">অ+</button>

                                <button type="button"
                                        @click="$store.reader.toggleBookmark({{ $article->id }}, '{{ route('account.bookmarks.toggle', $article) }}')"
                                        class="ml-1 rounded-md border border-line p-1.5 text-body
                                               hover:border-brand hover:text-brand"
                                        :class="$store.reader.has({{ $article->id }}) && 'border-brand text-brand'"
                                        :aria-pressed="$store.reader.has({{ $article->id }})"
                                        aria-label="সংরক্ষণ করুন">
                                    <x-ui.icon name="bookmark" class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </header>

                    {{-- Lead media --}}
                    @if ($article->video_url)
                        <x-article.video-embed :url="$article->video_url" class="mt-5" />
                    @elseif ($article->image_url)
                        <figure class="mt-5">
                            <img src="{{ $article->image_url }}" alt="{{ $article->image_caption }}"
                                 fetchpriority="high" decoding="async"
                                 class="w-full rounded-lg bg-surface-2 object-cover">
                            @if ($article->image_caption || $article->image_credit)
                                <figcaption class="mt-2 text-xs text-muted">
                                    {{ $article->image_caption }}
                                    @if ($article->image_credit)
                                        <span class="font-medium">{{ $article->image_credit }}</span>
                                    @endif
                                </figcaption>
                            @endif
                        </figure>
                    @endif

                    <x-article.share-bar :article="$article" class="mt-5" />

                    {{-- A live blog's timeline carries the story; the body is
                         just the standing intro above it. --}}
                    @if ($article->type === App\Enums\ArticleType::Live)
                        <x-article.live-timeline :article="$article" />
                    @endif

                    {{-- Body. `.article-body` is what the font-size control scales. --}}
                    <div x-ref="body"
                         class="article-body prose-editorial mt-6 max-w-none leading-[1.9] text-body">
                        {!! $article->body !!}
                    </div>

                    @if ($article->tags->isNotEmpty())
                        <div class="mt-7 flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold text-ink">বিষয়:</span>
                            @foreach ($article->tags as $tag)
                                <a href="{{ route('tag.show', $tag) }}"
                                   class="rounded-full bg-surface-2 px-3 py-1 text-sm text-body
                                          transition hover:bg-brand hover:text-white">
                                    {{ $tag->name }}
                                </a>
                            @endforeach
                        </div>
                    @endif

                    <x-article.share-bar :article="$article" class="mt-6 border-t border-line pt-5" />

                    {{-- Author box --}}
                    @if ($article->author?->bio)
                        <aside class="mt-8 flex gap-4 rounded-xl border border-line bg-surface p-5">
                            <img src="{{ $article->author->avatar_url }}" alt=""
                                 width="64" height="64" loading="lazy"
                                 class="h-16 w-16 shrink-0 rounded-full object-cover ring-1 ring-line">
                            <div>
                                <a href="{{ route('author.show', $article->author) }}"
                                   class="font-headline text-lg font-bold text-ink hover:text-brand">
                                    {{ $article->author->name }}
                                </a>
                                @if ($article->author->designation)
                                    <p class="text-xs text-muted">{{ $article->author->designation }}</p>
                                @endif
                                <p class="mt-1.5 text-sm text-body">{{ $article->author->bio }}</p>
                            </div>
                        </aside>
                    @endif

                    <x-ui.ad-slot position="in_article" class="mx-auto mt-8 w-full rounded-lg" />

                    {{-- Comments land in Phase 3; the anchor and count are here
                         so the article page does not need restructuring then. --}}
                    <section id="comments" class="mt-10 scroll-mt-24">
                        <x-ui.section-header
                            :title="'মন্তব্য ('.App\Support\Bangla::digits($article->comments_count).')'" />
                        <x-comment.thread :article="$article" />
                    </section>
                </article>

                <aside class="lg:col-span-4">
                    <div class="space-y-6 lg:sticky lg:top-20">
                        @if ($moreFromCategory->isNotEmpty())
                            <section class="rounded-xl border border-line bg-surface p-4">
                                <x-ui.section-header :title="$article->category->name.' থেকে আরও'"
                                                     :href="$article->category->url()"
                                                     :color="$article->category->color" />
                                <div class="space-y-4">
                                    @foreach ($moreFromCategory as $item)
                                        <x-article.card :article="$item" variant="list" :show-category="false" />
                                    @endforeach
                                </div>
                            </section>
                        @endif

                        <x-ui.ad-slot position="sidebar_rectangle" class="mx-auto w-full rounded-lg" />
                    </div>
                </aside>
            </div>

            {{-- Related --}}
            @if ($related->isNotEmpty())
                <section class="mt-12 border-t border-line pt-8">
                    <x-ui.section-header title="সম্পর্কিত খবর" />
                    <div class="grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($related as $item)
                            <x-article.card :article="$item" variant="standard" />
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
@endsection
