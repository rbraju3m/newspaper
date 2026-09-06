@props(['article'])

{{--
    The link to the same story in the other edition.

    **It appears only when the counterpart exists**, which is the whole design
    of the switcher: an always-visible control that 404s for the 90% of stories
    nobody has translated is worse than no control, because a reader learns
    within two clicks that it does not work and never presses it on the story
    that *was* translated.

    The label is always in the language being offered — "English" on a Bangla
    page, "বাংলা" on an English one — and never translated, because a reader
    who cannot read this page's language still has to be able to read the way
    out of it. That is also why `hreflang` and `lang` are set on the anchor:
    a screen reader switches voice for those two words.
--}}
@if ($counterpart = $article->counterpart())
    <a href="{{ $counterpart->url }}"
       lang="{{ $counterpart->locale }}"
       hreflang="{{ \App\Support\Locale::hreflang($counterpart->locale) }}"
       class="flex items-center gap-1.5 rounded-md border border-line px-2.5 py-1
              text-xs font-semibold text-body transition hover:border-brand hover:text-brand">
        <x-ui.icon name="globe" class="h-3.5 w-3.5" />
        {{ \App\Support\Locale::label($counterpart->locale) }}
    </a>

    @push('alternates')
        <link rel="alternate" hreflang="{{ \App\Support\Locale::hreflang($article->locale) }}"
              href="{{ $article->url }}">
        <link rel="alternate" hreflang="{{ \App\Support\Locale::hreflang($counterpart->locale) }}"
              href="{{ $counterpart->url }}">
        <link rel="alternate" hreflang="x-default"
              href="{{ $article->locale === \App\Support\Locale::DEFAULT ? $article->url : $counterpart->url }}">
    @endpush
@endif
