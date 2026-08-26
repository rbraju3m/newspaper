@extends('layouts.site')
@section('title', $article->title.' — '.config('site.name_bn'))
@section('description', $article->excerpt ?? '')
@section('canonical', route('video.show', $article))
@section('og_type', 'video.other')

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
        <div class="grid gap-8 lg:grid-cols-12">
            <div class="lg:col-span-8">
                <x-article.video-embed :url="$article->video_url" :title="$article->title" />

                <h1 class="mt-4 font-headline text-2xl font-bold text-ink lg:text-3xl">{{ $article->title }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                    <time>{{ $article->published_ago }}</time>
                    <span class="flex items-center gap-1">
                        <x-ui.icon name="eye" class="h-3.5 w-3.5" /> @bncount($article->views)
                    </span>
                </div>

                <x-article.share-bar :article="$article" class="mt-4" />

                @if ($article->body)
                    <div class="article-body prose-editorial mt-5 max-w-none text-body">
                        {!! $article->body !!}
                    </div>
                @endif
            </div>

            <aside class="lg:col-span-4">
                <x-ui.section-header title="আরও ভিডিও" :href="route('video.index')" />
                <div class="space-y-4">
                    @foreach ($playlist as $video)
                        <x-article.card :article="$video" variant="list" :show-category="false" />
                    @endforeach
                </div>
            </aside>
        </div>
    </div>
@endsection
