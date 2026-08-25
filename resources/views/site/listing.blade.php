@extends('layouts.site')

@section('title', $title.' — '.config('site.name_bn'))
@section('description', $description ?? '')

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="infiniteScroll({{ Js::from($articles->nextPageUrl()) }})">

        <header class="mb-6 border-b-2 border-brand pb-3">
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $title }}</h1>
            @isset($description)
                <p class="mt-1.5 text-base text-muted">{{ $description }}</p>
            @endisset

            {{-- Popular has a time window; the other listings do not. --}}
            @isset($days)
                <div class="mt-4 flex gap-2">
                    @foreach ([1 => 'আজ', 7 => 'এই সপ্তাহ', 30 => 'এই মাস'] as $value => $label)
                        <a href="{{ route('popular', ['days' => $value]) }}"
                           @class([
                               'rounded-full px-3.5 py-1.5 text-sm font-medium transition',
                               'bg-brand text-white' => $days === $value,
                               'border border-line bg-surface text-body hover:border-brand' => $days !== $value,
                           ])>{{ $label }}</a>
                    @endforeach
                </div>
            @endisset
        </header>

        <div class="grid gap-x-8 gap-y-5 lg:grid-cols-2" x-ref="list">
            @include('site.partials.article-list-items')

            <template x-if="loading">
                <x-ui.skeleton variant="list" :count="4" />
            </template>
        </div>

        @if ($articles->isEmpty())
            <x-ui.empty-state title="কোনো খবর পাওয়া যায়নি" />
        @endif

        @include('site.partials.load-more', ['paginator' => $articles])
    </div>
@endsection
