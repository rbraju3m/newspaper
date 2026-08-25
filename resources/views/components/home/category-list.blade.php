@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section>
        <x-ui.section-header :title="$block->heading()"
                             :href="$block->category?->url()"
                             :color="$block->category?->color" />

        <div class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
            @foreach ($data as $article)
                <x-article.card :article="$article" variant="list" :show-category="false" />
            @endforeach
        </div>
    </section>
@endif
