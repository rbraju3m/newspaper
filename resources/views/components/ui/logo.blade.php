@props(['mono' => false])

{{-- Wordmark rendered as text so it stays crisp, themeable and translatable.
     Swap for an <img> once the client supplies final artwork. --}}
<span {{ $attributes->merge(['class' => 'flex flex-col justify-center leading-none']) }}>
    <span class="font-headline text-2xl font-bold tracking-tight lg:text-4xl
                 {{ $mono ? 'text-current' : 'text-brand' }}">
        {{ config('site.name_bn') }}
    </span>
    <span class="mt-1 hidden text-2xs font-medium tracking-wide text-muted lg:block">
        {{ config('site.tagline') }}
    </span>
</span>
