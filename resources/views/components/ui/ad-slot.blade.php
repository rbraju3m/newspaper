@props(['position'])

@php
    $dim = config("site.ad_slots.$position");
    $ad = app(App\Services\AdService::class)->for($position);
@endphp

{{-- The box is always rendered at its full reserved size, filled or not, so
     late-arriving creative can never shift the page. --}}
<div {{ $attributes->merge(['class' => 'ad-slot rounded-sm']) }}
     @if ($dim) style="aspect-ratio: {{ $dim['w'] }}/{{ $dim['h'] }}; max-width: {{ $dim['w'] }}px" @endif
     data-ad-position="{{ $position }}">
    @if ($ad)
        <a href="{{ route('ads.click', $ad) }}" target="_blank" rel="noopener sponsored"
           class="block h-full w-full">
            <img src="{{ $ad->asset_url }}" alt="{{ $ad->title }}" loading="lazy" decoding="async"
                 width="{{ $dim['w'] ?? null }}" height="{{ $dim['h'] ?? null }}"
                 class="h-full w-full object-cover">
        </a>
    @else
        <span class="text-2xs uppercase tracking-widest text-muted/60">বিজ্ঞাপন</span>
    @endif
</div>
