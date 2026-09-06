@extends('layouts.site')
@section('title', $topic->display_name.' — '.\App\Support\Locale::siteName())
@section('description', $topic->display_description ?? '')

{{-- A tag and a topic exist in both editions the way a section does, so
     the pair is unconditional. An empty English listing is a thin page, not
     a broken one. --}}
@push('alternates')
    @foreach (\App\Support\Locale::ALL as $edition)
        <link rel="alternate" hreflang="{{ \App\Support\Locale::hreflang($edition) }}"
              href="{{ \App\Support\Locale::route('topic.show', $topic, $edition) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default"
          href="{{ \App\Support\Locale::route('topic.show', $topic, \App\Support\Locale::DEFAULT) }}">
@endpush

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-6 rounded-xl border border-line bg-surface p-5 lg:p-6"
                style="border-top: 3px solid {{ $topic->color }}">
            <span class="section-label text-2xs font-bold uppercase tracking-wide"
                  style="{{ \App\Support\Contrast::labelStyle($topic->color) }}">
                {{ __('বিশেষ আয়োজন') }}
            </span>
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $topic->display_name }}</h1>
            @if ($topic->display_description)
                <p class="mt-2 max-w-3xl text-base text-body">{{ $topic->display_description }}</p>
            @endif
            <p class="mt-2 text-sm text-muted">{{ __(':count টি খবর', ['count' => \App\Support\Fmt::digits($articles->total())]) }}</p>
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
