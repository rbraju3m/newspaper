{{-- Shared shell for errors raised while the application is healthy — a
     missing page, a refused permission, a stale form, a hit rate limit.

     Extends the site layout on purpose: at these codes the database is up and
     the header composers work, so a reader keeps the masthead and the nav and
     is one click from somewhere useful. The 5xx pages do the opposite and
     stand alone, because at those codes none of that can be relied on. --}}
@extends('layouts.site')

@section('title', View::yieldContent('heading').' — '.config('site.name_bn'))
@section('description', '')

@section('content')
    <div class="mx-auto flex max-w-2xl flex-col items-center px-4 py-16 text-center lg:py-24">
        <p class="lat font-headline text-6xl font-bold tabular-nums text-brand lg:text-7xl">
            @yield('code')
        </p>

        <h1 class="mt-4 font-headline text-2xl font-bold text-ink lg:text-3xl">
            @yield('heading')
        </h1>

        <p class="mt-3 max-w-lg text-base leading-relaxed text-body">
            @yield('message')
        </p>

        @hasSection('extra')
            <div class="mt-6 w-full max-w-md">@yield('extra')</div>
        @endif

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('home') }}"
               class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:opacity-90">
                প্রথম পাতায় ফিরুন
            </a>

            @yield('actions')
        </div>
    </div>
@endsection
