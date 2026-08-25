@props(['block', 'data'])

@if ($data && $data['articles']->isNotEmpty())
    @php $topic = $data['topic']; @endphp

    {{-- Running-story cluster, the pattern mzamin and Ittefaq use to keep an
         ongoing event coherent instead of scattering it across the page. --}}
    <section class="rounded-xl border border-line bg-surface p-5 lg:p-6"
             style="border-top: 3px solid {{ $topic->color }}">
        <div class="mb-4 flex items-end justify-between gap-4">
            <div>
                <span class="text-2xs font-bold uppercase tracking-wide" style="color: {{ $topic->color }}">
                    বিশেষ আয়োজন
                </span>
                <h2 class="font-headline text-xl font-bold text-ink lg:text-2xl">{{ $topic->name }}</h2>
            </div>
            <a href="{{ route('topic.show', $topic) }}"
               class="flex shrink-0 items-center gap-1 text-sm font-semibold text-muted hover:text-brand">
                সব খবর <x-ui.icon name="chevron-right" class="h-3.5 w-3.5" />
            </a>
        </div>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($data['articles'] as $article)
                <x-article.card :article="$article" variant="standard" :show-category="false" />
            @endforeach
        </div>
    </section>
@endif
