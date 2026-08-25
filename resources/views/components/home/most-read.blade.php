@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section class="rounded-xl border border-line bg-surface p-4">
        <x-ui.section-header :title="$block->heading() ?: 'সর্বাধিক পঠিত'" :href="route('popular')" />

        <ol class="space-y-3.5">
            @foreach ($data as $i => $article)
                <li class="flex gap-3">
                    {{-- The rank number is the hierarchy here, so it is set in
                         the Latin face for tabular alignment. --}}
                    <span class="lat w-6 shrink-0 text-xl font-bold leading-none
                                 {{ $i < 3 ? 'text-brand' : 'text-line-strong' }}">
                        @bn($i + 1)
                    </span>
                    <x-article.card :article="$article" variant="compact"
                                    :show-category="false" class="min-w-0 flex-1" />
                </li>
            @endforeach
        </ol>
    </section>
@endif
