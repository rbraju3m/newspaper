@props(['url', 'title' => null])

@php
    // Extract the YouTube/Vimeo id rather than embedding the watch URL, and use
    // youtube-nocookie so the player does not set tracking cookies before the
    // reader has chosen to play.
    preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $yt);
    preg_match('~vimeo\.com/(\d+)~', $url, $vm);

    $embed = match (true) {
        ! empty($yt[1]) => 'https://www.youtube-nocookie.com/embed/'.$yt[1].'?rel=0',
        ! empty($vm[1]) => 'https://player.vimeo.com/video/'.$vm[1],
        default => null,
    };
@endphp

@if ($embed)
    <figure {{ $attributes }}>
        {{-- Fixed aspect box: the iframe can never shift the page as it loads. --}}
        <div class="aspect-video overflow-hidden rounded-lg bg-black">
            <iframe src="{{ $embed }}"
                    title="{{ $title ?? 'ভিডিও' }}"
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    class="h-full w-full border-0"></iframe>
        </div>
    </figure>
@else
    <p {{ $attributes->merge(['class' => 'rounded-lg border border-line p-4 text-sm text-muted']) }}>
        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-link underline">
            ভিডিওটি দেখতে এখানে ক্লিক করুন
        </a>
    </p>
@endif
