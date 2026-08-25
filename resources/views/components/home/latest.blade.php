@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section class="rounded-xl border border-line bg-surface p-4">
        <x-ui.section-header :title="$block->heading() ?: 'সর্বশেষ'" :href="route('latest')" />

        {{-- Timeline rail: a vertical line with a dot per item makes a long
             list of timestamps scannable instead of a wall of text. --}}
        <ul class="relative space-y-4 before:absolute before:bottom-2 before:left-[3px] before:top-2
                   before:w-px before:bg-line">
            @foreach ($data as $article)
                <li class="relative pl-5">
                    <span class="absolute left-0 top-1.5 h-[7px] w-[7px] rounded-full
                                 {{ $loop->first ? 'bg-brand' : 'bg-line-strong' }}"></span>
                    <time class="text-2xs font-medium text-muted"
                          datetime="{{ $article->published_at?->toIso8601String() }}">
                        {{ $article->published_ago }}
                    </time>
                    <x-article.card :article="$article" variant="compact" :show-meta="false" />
                </li>
            @endforeach
        </ul>
    </section>
@endif
