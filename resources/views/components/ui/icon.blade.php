@props(['name', 'stroke' => 1.75])

@php
    // Inline SVGs — no icon-font request, no CLS, and they inherit currentColor.
    $paths = [
        'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close'     => '<path d="M6 6l12 12M18 6L6 18"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>',
        'monitor'   => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0116 0"/>',
        'bookmark'  => '<path d="M6 3h12a1 1 0 011 1v17l-7-4-7 4V4a1 1 0 011-1z"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'bell'      => '<path d="M18 9a6 6 0 10-12 0c0 5-2 6-2 6h16s-2-1-2-6z"/><path d="M13.7 20a2 2 0 01-3.4 0"/>',
        'bell-off'  => '<path d="M9 4.6A6 6 0 0118 9c0 1.9.3 3.3.7 4.3M5.8 5.8C6 6.8 6 7.8 6 9c0 5-2 6-2 6h13"/><path d="M13.7 20a2 2 0 01-3.4 0M3 3l18 18"/>',
        'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
        'comment'   => '<path d="M21 12a8 8 0 01-8 8H7l-4 3V12a8 8 0 018-8h2a8 8 0 018 8z"/>',
        'share'     => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
        'play'      => '<path d="M8 5.5v13l11-6.5z"/>',
        'camera'    => '<path d="M3 8a2 2 0 012-2h2l1.5-2h7L17 6h2a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><circle cx="12" cy="12.5" r="3.5"/>',
        'chevron-down'  => '<path d="M6 9l6 6 6-6"/>',
        'chevron-left'  => '<path d="M15 6l-6 6 6 6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'arrow-left'    => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'newspaper' => '<path d="M4 5h13v14a2 2 0 002-2V9h1v8a3 3 0 01-3 3H5a1 1 0 01-1-1z"/><path d="M7 8h7M7 11h7M7 14h4"/>',
        'copy'      => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h10"/>',
        'check'     => '<path d="M20 6L9 17l-5-5"/>',
        'print'     => '<path d="M6 9V3h12v6M6 18H4a1 1 0 01-1-1v-5a2 2 0 012-2h14a2 2 0 012 2v5a1 1 0 01-1 1h-2"/><rect x="6" y="14" width="12" height="7" rx="1"/>',
        'mail'      => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 7l10 6 10-6"/>',
        'logout'    => '<path d="M15 17l5-5-5-5M20 12H9M12 3H6a1 1 0 00-1 1v16a1 1 0 001 1h6"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-2.7 1.1v.3a2 2 0 11-4 0v-.2a1.6 1.6 0 00-2.8-1.1l-.1.1a2 2 0 11-2.8-2.8l.1-.1A1.6 1.6 0 004 15.4a2 2 0 01-2-2 2 2 0 012-2 1.6 1.6 0 001.5-1.1 1.6 1.6 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1A1.6 1.6 0 009 4.6a2 2 0 012-2 2 2 0 012 2 1.6 1.6 0 001.1 1.5 1.6 1.6 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 00-.3 1.8V11a2 2 0 012 2 2 2 0 01-2 2z"/>',
        'facebook'  => '<path d="M14 9h3V6h-3a4 4 0 00-4 4v2H7v3h3v7h3v-7h3l1-3h-4v-2a1 1 0 011-1z" fill="currentColor" stroke="none"/>',
        'youtube'   => '<path d="M22 12s0-3.2-.4-4.7a2.5 2.5 0 00-1.8-1.8C18.3 5 12 5 12 5s-6.3 0-7.8.5A2.5 2.5 0 002.4 7.3C2 8.8 2 12 2 12s0 3.2.4 4.7a2.5 2.5 0 001.8 1.8C5.7 19 12 19 12 19s6.3 0 7.8-.5a2.5 2.5 0 001.8-1.8C22 15.2 22 12 22 12z" fill="currentColor" stroke="none"/><path d="M10 15V9l5 3z" fill="var(--color-surface)" stroke="none"/>',
        'twitter'   => '<path d="M18.9 3H22l-7 8 8.2 10h-6.4l-5-6.1L6 21H2.9l7.5-8.6L2.5 3H9l4.5 5.6zm-1.1 16h1.7L7.3 4.7H5.5z" fill="currentColor" stroke="none"/>',
        'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor" stroke="none"/>',
        'linkedin'  => '<path d="M4.98 3.5a2.5 2.5 0 100 5 2.5 2.5 0 000-5zM3 9h4v12H3zM10 9h3.8v1.7A4.2 4.2 0 0117.5 9c3 0 3.5 2 3.5 4.6V21h-4v-6.4c0-1.5 0-3.5-2.1-3.5s-2.4 1.6-2.4 3.4V21h-4z" fill="currentColor" stroke="none"/>',
        'tiktok'    => '<path d="M16 3c.4 2.4 2 4 4.4 4.2v3.2A7.5 7.5 0 0116 8.9v6.4a5.9 5.9 0 11-5.9-5.9c.3 0 .6 0 .9.1v3.3a2.7 2.7 0 102 2.6V3z" fill="currentColor" stroke="none"/>',
        'whatsapp'  => '<path d="M12 2a10 10 0 00-8.6 15L2 22l5.2-1.4A10 10 0 1012 2z"/><path d="M8.5 8c.3-.6.6-.6.9-.6h.7c.2 0 .5 0 .7.6l.8 1.8c.1.3 0 .5-.1.7l-.5.6c-.2.2-.3.4-.1.7a7 7 0 003.3 2.9c.3.1.5.1.7-.1l.6-.7c.2-.2.4-.3.7-.2l1.8.9c.3.1.5.3.5.5v.7c0 .3-.1.7-.6 1a3 3 0 01-2 .7c-1.6 0-3.9-1.2-5.5-2.8S8 12 8 10.4c0-.9.3-1.7.5-2.4z"/>',
        'telegram'  => '<path d="M21.9 4.3L18.8 19c-.2 1-.9 1.3-1.7.8l-4.6-3.4-2.2 2.1c-.3.3-.5.5-1 .5l.4-4.9 8.9-8c.4-.3-.1-.5-.6-.2L7.1 12.8l-4.7-1.5c-1-.3-1-1 .2-1.5l18.4-7.1c.9-.3 1.6.2 1.3 1.6z" fill="currentColor" stroke="none"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'inline-block shrink-0']) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    {!! $paths[$name] ?? '' !!}
</svg>
