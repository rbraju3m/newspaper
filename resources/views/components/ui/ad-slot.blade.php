@props(['position'])

@php
    $dim = config("site.ad_slots.$position");
    $ad = app(App\Services\AdService::class)->for($position);
@endphp

{{-- The box is always rendered at its full reserved size, filled or not, so
     late-arriving creative can never shift the page. --}}
<div {{ $attributes->merge(['class' => 'ad-slot rounded-sm']) }}
     @if ($dim) style="aspect-ratio: {{ $dim['w'] }}/{{ $dim['h'] }}; max-width: {{ $dim['w'] }}px" @endif
     data-ad-position="{{ $position }}"
     @if ($ad) data-ad-id="{{ $ad->id }}" @endif>
    @if ($ad)
        {{-- Only a link when there is somewhere to go. `click()` 404s on an ad
             with no `url`, and a house ad or a placeholder frequently has
             none — so wrapping unconditionally made every one of them a link
             to an error page, which is worse than a picture that does not
             respond to a click. --}}
        @if ($ad->url)
            <a href="{{ route('ads.click', $ad) }}" target="_blank" rel="noopener sponsored"
               class="block h-full w-full">
                <x-ui.ad-creative :ad="$ad" :dim="$dim" />
            </a>
        @else
            <x-ui.ad-creative :ad="$ad" :dim="$dim" />
        @endif
    @else
        <span class="text-2xs uppercase tracking-widest text-muted/60">বিজ্ঞাপন</span>
    @endif
</div>
