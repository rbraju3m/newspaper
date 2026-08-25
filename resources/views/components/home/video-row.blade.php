@props(['block', 'data'])

@if ($data->isNotEmpty())
    {{-- Dark band. Video sections read better against dark, and it gives the
         long scroll of a front page a visual break. --}}
    <section class="-mx-4 bg-[#14171A] px-4 py-8 text-white lg:mx-0 lg:rounded-xl lg:px-8">
        <div class="mb-4 flex items-end justify-between gap-4 border-b-2 border-brand pb-2">
            <h2 class="font-headline text-xl font-bold text-white lg:text-2xl">
                {{ $block->heading() ?: 'ভিডিও' }}
            </h2>
            <a href="{{ route('video.index') }}"
               class="flex shrink-0 items-center gap-1 pb-1 text-sm font-semibold text-white/70 hover:text-white">
                আরও দেখুন <x-ui.icon name="chevron-right" class="h-3.5 w-3.5" />
            </a>
        </div>

        <div class="no-scrollbar flex gap-4 overflow-x-auto sm:grid sm:grid-cols-3 sm:overflow-visible lg:grid-cols-4">
            @foreach ($data as $article)
                <x-article.card :article="$article"
                                variant="rail"
                                :show-category="false"
                                class="[&_h3]:text-white [&_time]:text-white/60 sm:w-auto" />
            @endforeach
        </div>
    </section>
@endif
