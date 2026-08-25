@props(['block', 'data'])

@php
    $lead = $data->first();
    $rest = $data->skip(1);
@endphp

@if ($lead)
    <section class="grid gap-6 lg:grid-cols-12">
        {{-- Lead story. Its image is the LCP element, so it loads eagerly and
             at high priority while everything else stays lazy. --}}
        <div class="lg:col-span-7">
            <x-article.card :article="$lead" variant="hero" :eager="true" />
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:col-span-5 lg:grid-cols-1">
            @foreach ($rest->take(2) as $article)
                <x-article.card :article="$article" variant="feature" />
            @endforeach

            @if ($rest->count() > 2)
                <div class="space-y-3 border-t border-line pt-3 sm:col-span-2 lg:col-span-1">
                    @foreach ($rest->skip(2) as $article)
                        <x-article.card :article="$article" variant="list" :show-category="false" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endif
