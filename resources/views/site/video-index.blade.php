@extends('layouts.site')
@section('title', 'ভিডিও — '.config('site.name_bn'))

@section('content')
    <div class="bg-[#14171A] text-white">
        <div class="mx-auto max-w-site px-4 py-8">
            <h1 class="font-headline text-3xl font-bold text-white lg:text-4xl">ভিডিও</h1>

            @if ($featured)
                <div class="mt-6 grid gap-6 lg:grid-cols-12">
                    <div class="lg:col-span-8">
                        <x-article.video-embed :url="$featured->video_url" :title="$featured->title" />
                        <h2 class="mt-3 font-headline text-xl font-bold text-white lg:text-2xl">
                            {{ $featured->title }}
                        </h2>
                        <p class="mt-1.5 text-sm text-white/60">{{ $featured->published_ago }}</p>
                    </div>

                    <div class="space-y-4 lg:col-span-4 lg:max-h-[420px] lg:overflow-y-auto">
                        @foreach ($videos->skip(1)->take(6) as $video)
                            <x-article.card :article="$video" variant="list" :show-category="false"
                                            class="[&_h3]:text-white [&_time]:text-white/60" />
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="mx-auto max-w-site px-4 py-8">
        <x-ui.section-header title="সব ভিডিও" />
        <div class="grid gap-x-6 gap-y-7 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($videos->skip(7) as $video)
                <x-article.card :article="$video" variant="standard" />
            @endforeach
        </div>
        <div class="mt-8">{{ $videos->links() }}</div>
    </div>
@endsection
