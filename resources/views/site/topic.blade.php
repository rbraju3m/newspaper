@extends('layouts.site')
@section('title', $topic->name.' — '.config('site.name_bn'))
@section('description', $topic->description ?? '')

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-6 rounded-xl border border-line bg-surface p-5 lg:p-6"
                style="border-top: 3px solid {{ $topic->color }}">
            <span class="text-2xs font-bold uppercase tracking-wide" style="color: {{ $topic->color }}">
                বিশেষ আয়োজন
            </span>
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $topic->name }}</h1>
            @if ($topic->description)
                <p class="mt-2 max-w-3xl text-base text-body">{{ $topic->description }}</p>
            @endif
            <p class="mt-2 text-sm text-muted">@bn($articles->total()) টি খবর</p>
        </header>

        <div class="grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-4" x-ref="list">
            @include('site.partials.article-grid-items')

            {{-- Occupies the same grid cells the incoming cards will fill. --}}
            <template x-if="loading">
                <x-ui.skeleton variant="card" :count="4" />
            </template>
        </div>

        @include('site.partials.load-more', ['paginator' => $articles])
    </div>
@endsection
