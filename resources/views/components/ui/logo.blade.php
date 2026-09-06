@props(['mono' => false])

{{-- Wordmark rendered as text so it stays crisp, themeable and translatable.
     Swap for an <img> once the client supplies final artwork. --}}
<span {{ $attributes->merge(['class' => 'flex flex-col justify-center leading-none']) }}>
    <span class="font-headline text-2xl font-bold tracking-tight lg:text-4xl
                 {{ $mono ? 'text-current' : 'text-brand' }}">
        {{ \App\Support\Locale::siteName() }}
    </span>
    {{-- The tagline is a line of Bangla copywriting, not a label. It is
         dropped in the English edition rather than machine-translated into
         something that reads like a slogan nobody wrote. --}}
    @if (\App\Support\Locale::isDefault())
        <span class="mt-1 hidden text-2xs font-medium tracking-wide text-muted lg:block">
            {{ config('site.tagline') }}
        </span>
    @endif
</span>
