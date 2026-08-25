@extends('layouts.site')
@section('title', $gallery->title.' — '.config('site.name_bn'))
@section('description', $gallery->description)

@section('content')
    {{-- Lightbox: keyboard-navigable, focus-trapped, and it locks body scroll
         while open. --}}
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7"
         x-data="{
            open: false,
            index: 0,
            total: {{ $gallery->images->count() }},
            show(i) { this.index = i; this.open = true; document.body.style.overflow = 'hidden'; },
            close() { this.open = false; document.body.style.overflow = ''; },
            next() { this.index = (this.index + 1) % this.total; },
            prev() { this.index = (this.index - 1 + this.total) % this.total; },
         }"
         @keydown.escape.window="close()"
         @keydown.arrow-right.window="open && next()"
         @keydown.arrow-left.window="open && prev()">

        <header class="mb-6">
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">{{ $gallery->title }}</h1>
            @if ($gallery->description)
                <p class="mt-2 max-w-3xl text-base text-body">{{ $gallery->description }}</p>
            @endif
            <p class="mt-2 text-sm text-muted">
                @bn($gallery->images->count()) টি ছবি ·
                {{ App\Support\Bangla::ago($gallery->published_at) }}
            </p>
        </header>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($gallery->images as $i => $image)
                <button type="button" @click="show({{ $i }})"
                        class="group relative block aspect-square overflow-hidden rounded-lg bg-surface-2">
                    <img src="{{ $image->url }}" alt="{{ $image->caption }}" loading="lazy"
                         decoding="async"
                         @if ($image->srcset)
                             srcset="{{ $image->srcset }}" sizes="(min-width:1024px) 300px, 45vw"
                         @endif
                         class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                </button>
            @endforeach
        </div>

        {{-- Lightbox overlay --}}
        <div x-show="open" x-cloak x-transition.opacity
             class="fixed inset-0 z-[60] flex flex-col bg-black/95" role="dialog" aria-modal="true">
            <div class="flex items-center justify-between p-4 text-white">
                <span class="lat text-sm">
                    <span x-text="index + 1"></span> / <span x-text="total"></span>
                </span>
                <button type="button" @click="close()" aria-label="বন্ধ করুন"
                        class="rounded-md p-2 hover:bg-white/10">
                    <x-ui.icon name="close" class="h-6 w-6" />
                </button>
            </div>

            <div class="relative flex flex-1 items-center justify-center px-4 pb-4">
                @foreach ($gallery->images as $i => $image)
                    <figure x-show="index === {{ $i }}" x-cloak class="max-h-full">
                        <img src="{{ $image->url }}" alt="{{ $image->caption }}"
                             class="mx-auto max-h-[75vh] w-auto object-contain">
                        @if ($image->caption)
                            <figcaption class="mt-3 text-center text-sm text-white/80">
                                {{ $image->caption }}
                                @if ($image->credit)
                                    <span class="text-white/50">— {{ $image->credit }}</span>
                                @endif
                            </figcaption>
                        @endif
                    </figure>
                @endforeach

                <button type="button" @click="prev()" aria-label="আগের ছবি"
                        class="absolute left-2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20">
                    <x-ui.icon name="chevron-left" class="h-5 w-5" />
                </button>
                <button type="button" @click="next()" aria-label="পরের ছবি"
                        class="absolute right-2 rounded-full bg-white/10 p-3 text-white hover:bg-white/20">
                    <x-ui.icon name="chevron-right" class="h-5 w-5" />
                </button>
            </div>
        </div>

        @if ($more->isNotEmpty())
            <section class="mt-12 border-t border-line pt-8">
                <x-ui.section-header title="আরও গ্যালারি" :href="route('photo.index')" />
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($more as $g)
                        <a href="{{ route('photo.show', $g) }}" class="group block">
                            <figure class="aspect-square overflow-hidden rounded-lg bg-surface-2">
                                @if ($g->cover)
                                    <img src="{{ asset('storage/'.$g->cover) }}" alt="" loading="lazy"
                                         class="h-full w-full object-cover transition group-hover:scale-105">
                                @endif
                            </figure>
                            <h3 class="mt-1.5 text-xs font-semibold text-ink clamp-2">{{ $g->title }}</h3>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
