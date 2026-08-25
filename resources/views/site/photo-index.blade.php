@extends('layouts.site')
@section('title', 'ফটো গ্যালারি — '.config('site.name_bn'))

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
        <header class="mb-6 border-b-2 pb-3" style="border-bottom-color: #4F46E5">
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">ফটো গ্যালারি</h1>
        </header>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($galleries as $gallery)
                <a href="{{ route('photo.show', $gallery) }}" class="group block">
                    <figure class="relative aspect-[4/3] overflow-hidden rounded-lg bg-surface-2">
                        @if ($gallery->cover)
                            <img src="{{ asset('storage/'.$gallery->cover) }}" alt="" loading="lazy"
                                 decoding="async" sizes="(min-width:1024px) 300px, 45vw"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                        @endif
                        <span class="absolute bottom-1.5 right-1.5 flex items-center gap-1 rounded
                                     bg-black/75 px-1.5 py-0.5 text-2xs font-medium text-white">
                            <x-ui.icon name="camera" class="h-3 w-3" /> @bn($gallery->images_count)
                        </span>
                    </figure>
                    <h2 class="mt-2 font-headline text-base font-semibold text-ink clamp-2
                               group-hover:text-brand">{{ $gallery->title }}</h2>
                    <time class="mt-1 block text-xs text-muted">
                        {{ App\Support\Bangla::ago($gallery->published_at) }}
                    </time>
                </a>
            @endforeach
        </div>

        @if ($galleries->isEmpty())
            <x-ui.empty-state icon="camera" title="এখনো কোনো গ্যালারি নেই" />
        @endif

        <div class="mt-8">{{ $galleries->links() }}</div>
    </div>
@endsection
