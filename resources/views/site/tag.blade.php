@extends('layouts.site')
@section('title', $tag->display_name.' — '.\App\Support\Locale::siteName())

{{-- A tag and a topic exist in both editions the way a section does, so
     the pair is unconditional. An empty English listing is a thin page, not
     a broken one. --}}
@push('alternates')
    @foreach (\App\Support\Locale::ALL as $edition)
        <link rel="alternate" hreflang="{{ \App\Support\Locale::hreflang($edition) }}"
              href="{{ \App\Support\Locale::route('tag.show', $tag, $edition) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default"
          href="{{ \App\Support\Locale::route('tag.show', $tag, \App\Support\Locale::DEFAULT) }}">
@endpush

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-6 border-b-2 border-brand pb-3">
            <p class="text-sm text-muted">{{ __('বিষয়') }}</p>
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $tag->display_name }}</h1>
            <p class="mt-1 text-sm text-muted">{{ __(':count টি খবর', ['count' => \App\Support\Fmt::digits($articles->total())]) }}</p>
        </header>

        <div class="grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-4" x-ref="list">
            @include('site.partials.article-grid-items')

            {{-- Occupies the same grid cells the incoming cards will fill. --}}
            <template x-if="loading">
                <x-ui.skeleton variant="card" :count="4" />
            </template>
        </div>

        @if ($articles->isEmpty())
            <x-ui.empty-state :title="__('এই বিষয়ে কোনো খবর নেই')" />
        @endif

        @include('site.partials.load-more', ['paginator' => $articles])
    </div>
@endsection
