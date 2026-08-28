@props(['ad', 'dim' => null])

{{-- The creative itself, extracted so the linked and unlinked branches of
     `ad-slot` cannot drift apart — they did once already, and an <img> that
     differs between them is the sort of thing nobody notices.

     `srcset` only when the linked media row actually has a ladder. A creative
     from before the media library, or an external URL, has none and gets a
     plain `src`. A *single*-rung ladder is still offered — see
     `Ad::creativeSrcset` — because the rung is WebP and the fallback is the
     original JPEG, which is a saving even at identical pixel dimensions.

     `sizes` is exact rather than a guess: the slot's box is `max-width: {w}px`
     with the image at `w-full`, so the rendered width is the slot width until
     the viewport is narrower than it, and 100vw after that. --}}
<img src="{{ $ad->asset_url }}" alt="{{ $ad->title }}"
     @if ($ad->creative_srcset)
         srcset="{{ $ad->creative_srcset }}"
         sizes="{{ $dim ? '(max-width: '.$dim['w'].'px) 100vw, '.$dim['w'].'px' : '100vw' }}"
     @endif
     loading="lazy" decoding="async"
     width="{{ $dim['w'] ?? null }}" height="{{ $dim['h'] ?? null }}"
     class="h-full w-full object-cover">
