@extends('layouts.site')
@section('title', $author->name.' — '.\App\Support\Locale::siteName())
@section('description', $author->display_bio ?? '')

{{-- One person, two pages: the pair is unconditional, and each lists that
     reporter's stories in its own edition. --}}
@push('alternates')
    @foreach (\App\Support\Locale::ALL as $edition)
        <link rel="alternate" hreflang="{{ \App\Support\Locale::hreflang($edition) }}"
              href="{{ \App\Support\Locale::route('author.show', $author, $edition) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default"
          href="{{ \App\Support\Locale::route('author.show', $author, \App\Support\Locale::DEFAULT) }}">
@endpush

@push('schema')
<script type="application/ld+json">
{!! json_encode(array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Person',
    'name' => $author->name,
    'jobTitle' => $author->display_designation,
    'description' => $author->display_bio,
    'url' => $author->url(),
    'image' => $author->avatar_photo_url,
]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endpush

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-7 flex flex-col items-center gap-4 rounded-xl border border-line
                       bg-surface p-6 text-center sm:flex-row sm:text-left">
            <img src="{{ $author->avatar_url }}" alt="" width="88" height="88"
                 class="h-22 w-22 shrink-0 rounded-full object-cover ring-2 ring-line">
            <div>
                <h1 class="font-headline text-2xl font-bold text-ink lg:text-3xl">{{ $author->name }}</h1>
                @if ($author->display_designation)
                    <p class="text-sm font-medium text-brand">{{ $author->display_designation }}</p>
                @endif
                @if ($author->display_bio)
                    <p class="mt-2 max-w-2xl text-sm text-body">{{ $author->display_bio }}</p>
                @endif
                <p class="mt-2 text-xs text-muted">{{ __(':count টি প্রতিবেদন', ['count' => \App\Support\Fmt::digits($articles->total())]) }}</p>
            </div>
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
