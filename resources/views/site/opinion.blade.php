@extends('layouts.site')

@section('title', 'মতামত — '.config('site.name_bn'))
@section('description', $description ?? '')

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-6 border-b-2 pb-3" style="border-bottom-color: #B45309">
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">মতামত</h1>
            <p class="mt-1.5 text-base text-muted">{{ $description ?? '' }}</p>
        </header>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" x-ref="list">
            @foreach ($articles as $article)
                <article class="group rounded-xl border border-line bg-surface p-5">
                    <div class="flex items-center gap-3">
                        <img src="{{ $article->author?->avatar_url }}" alt="" width="48" height="48"
                             loading="lazy" class="h-12 w-12 rounded-full object-cover ring-1 ring-line">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-ink">{{ $article->author?->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $article->author?->designation }}</p>
                        </div>
                    </div>

                    <a href="{{ $article->url }}">
                        <h2 class="mt-3 font-headline text-lg font-bold leading-snug text-ink
                                   clamp-3 group-hover:text-brand">{{ $article->title }}</h2>
                    </a>
                    <p class="mt-2 text-sm text-muted clamp-3">{{ $article->excerpt }}</p>
                    <time class="mt-3 block text-xs text-muted">{{ $article->published_ago }}</time>
                </article>
            @endforeach
        </div>

        @include('site.partials.load-more', ['paginator' => $articles])
    </div>
@endsection
