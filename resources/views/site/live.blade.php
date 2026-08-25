@extends('layouts.site')
@section('title', 'লাইভ — '.config('site.name_bn'))

@section('content')
    <div class="bg-[#14171A] text-white">
        <div class="mx-auto max-w-site px-4 py-8">
            <h1 class="flex items-center gap-2.5 font-headline text-3xl font-bold text-white lg:text-4xl">
                <span class="relative flex h-2.5 w-2.5">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand opacity-75"></span>
                    <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-brand"></span>
                </span>
                লাইভ টিভি
            </h1>

            @if ($streamUrl)
                <x-article.video-embed :url="$streamUrl" title="লাইভ স্ট্রিম" class="mt-5" />
            @else
                <div class="mt-5 flex aspect-video items-center justify-center rounded-lg bg-black/50">
                    <p class="text-sm text-white/60">লাইভ স্ট্রিম এই মুহূর্তে বন্ধ আছে।</p>
                </div>
            @endif
        </div>
    </div>

    @if ($liveBlogs->isNotEmpty())
        <div class="mx-auto max-w-site px-4 py-8">
            <x-ui.section-header title="লাইভ আপডেট" />
            <div class="grid gap-x-8 gap-y-5 lg:grid-cols-2">
                @foreach ($liveBlogs as $article)
                    <x-article.card :article="$article" variant="list" />
                @endforeach
            </div>
        </div>
    @endif
@endsection
