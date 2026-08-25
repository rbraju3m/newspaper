@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section>
        <x-ui.section-header title="মতামত" :href="route('opinion')" color="#B45309" />

        {{-- Columnist faces carry this section — readers follow the writer, not
             the headline, which is why every paper runs opinion with portraits. --}}
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($data as $article)
                <article class="group flex gap-3 rounded-lg border border-line bg-surface p-3
                                transition hover:border-brand/40 hover:shadow-card">
                    <img src="{{ $article->author?->avatar_url }}" alt="" width="56" height="56"
                         loading="lazy" decoding="async"
                         class="h-14 w-14 shrink-0 rounded-full object-cover ring-1 ring-line">

                    <div class="min-w-0">
                        <a href="{{ $article->url }}">
                            <h3 class="font-headline text-base font-semibold leading-snug text-ink
                                       clamp-3 transition group-hover:text-brand">
                                {{ $article->title }}
                            </h3>
                        </a>
                        @if ($article->author)
                            <a href="{{ route('author.show', $article->author) }}"
                               class="mt-1 block truncate text-xs font-medium text-muted hover:text-brand">
                                {{ $article->author->name }}
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif
