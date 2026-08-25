@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section class="grid gap-8 lg:grid-cols-3">
        @foreach ($data as $column)
            <div>
                <x-ui.section-header :title="$column['category']->name"
                                     :href="$column['category']->url()"
                                     :color="$column['category']->color" />

                @php $lead = $column['articles']->first(); @endphp

                <x-article.card :article="$lead" variant="standard" :show-category="false" />

                <div class="mt-4 space-y-3 border-t border-line pt-3">
                    @foreach ($column['articles']->skip(1) as $article)
                        <x-article.card :article="$article" variant="compact" :show-category="false" />
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>
@endif
