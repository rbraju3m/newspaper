@extends('layouts.site')
@section('title', $tag->name.' — '.config('site.name_bn'))

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-6 border-b-2 border-brand pb-3">
            <p class="text-sm text-muted">বিষয়</p>
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $tag->name }}</h1>
            <p class="mt-1 text-sm text-muted">@bn($articles->total()) টি খবর</p>
        </header>

        <div class="grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-4" x-ref="list">
            @include('site.partials.article-grid-items')

            {{-- Occupies the same grid cells the incoming cards will fill. --}}
            <template x-if="loading">
                <x-ui.skeleton variant="card" :count="4" />
            </template>
        </div>

        @if ($articles->isEmpty())
            <x-ui.empty-state title="এই বিষয়ে কোনো খবর নেই" />
        @endif

        @include('site.partials.load-more', ['paginator' => $articles])
    </div>
@endsection
