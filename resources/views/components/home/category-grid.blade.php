@props(['block', 'data'])

@php
    $lead = $data->first();
    $rest = $data->skip(1);
    $category = $block->category;
@endphp

@if ($lead)
    <section>
        <x-ui.section-header :title="$block->heading()"
                             :href="$category?->url()"
                             :color="$category?->color" />

        <div class="grid gap-6 lg:grid-cols-12">
            <div class="lg:col-span-5">
                <x-article.card :article="$lead" variant="feature" :show-excerpt="true" :show-category="false" />
            </div>

            <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2 lg:col-span-7">
                @foreach ($rest as $article)
                    <x-article.card :article="$article" variant="standard" :show-category="false" />
                @endforeach
            </div>
        </div>
    </section>
@endif
