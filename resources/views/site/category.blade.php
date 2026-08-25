@extends('layouts.site')

@section('title', ($category->meta_title ?: $category->name).' — '.config('site.name_bn'))
@section('description', $category->meta_description ?: 'সর্বশেষ '.$category->name.' বিষয়ক খবর।')
@section('canonical', $category->url())

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        @include('site.partials.breadcrumb', [
            'items' => $ancestors->map(fn ($a) => ['label' => $a->name, 'url' => $a->url()])
                ->push(['label' => $category->name])
                ->all(),
        ])

        <header class="mb-6 border-b-2 pb-3" style="border-bottom-color: {{ $category->color }}">
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $category->name }}</h1>
            @if ($category->description)
                <p class="mt-1.5 max-w-3xl text-base text-muted">{{ $category->description }}</p>
            @endif

            @if ($children->isNotEmpty())
                <div class="no-scrollbar mt-4 flex gap-2 overflow-x-auto">
                    @foreach ($children as $child)
                        <a href="{{ $child->url() }}"
                           class="shrink-0 rounded-full border border-line bg-surface px-3.5 py-1.5
                                  text-sm font-medium text-body transition hover:border-brand hover:text-brand">
                            {{ $child->name }}
                        </a>
                    @endforeach
                </div>
            @endif
        </header>

        @if ($lead)
            <div class="mb-8 grid gap-6 lg:grid-cols-12">
                <div class="lg:col-span-8">
                    <x-article.card :article="$lead" variant="hero" :eager="true" :show-category="false" />
                </div>
                <aside class="lg:col-span-4">
                    <x-ui.ad-slot position="sidebar_halfpage" class="w-full rounded-lg" />
                </aside>
            </div>
        @endif

        <div class="grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-4" x-ref="list">
            @include('site.partials.article-grid-items')
        </div>

        @if ($articles->isEmpty() && ! $lead)
            <x-ui.empty-state title="এই বিভাগে এখনো কোনো খবর নেই"
                              message="শীঘ্রই নতুন খবর যুক্ত হবে।" />
        @endif

        @include('site.partials.load-more', ['paginator' => $articles])
    </div>
@endsection
