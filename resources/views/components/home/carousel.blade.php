@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section>
        <x-ui.section-header :title="$block->heading()"
                             :href="$block->category?->url()"
                             :color="$block->category?->color" />

        {{-- Horizontal rail. On touch it is a native scroll; on desktop the
             arrows nudge it. No JS library, no layout shift. --}}
        <div x-data="{
                scroll(dir) {
                    $refs.rail.scrollBy({ left: dir * $refs.rail.clientWidth * 0.8, behavior: 'smooth' });
                }
             }" class="relative">
            <div x-ref="rail" class="no-scrollbar flex snap-x snap-mandatory gap-4 overflow-x-auto pb-1">
                @foreach ($data as $article)
                    <x-article.card :article="$article" variant="rail" class="snap-start" />
                @endforeach
            </div>

            <button type="button" @click="scroll(-1)" aria-label="আগের"
                    class="absolute -left-3 top-[28%] hidden h-9 w-9 items-center justify-center
                           rounded-full border border-line bg-surface shadow-card hover:text-brand lg:flex">
                <x-ui.icon name="chevron-left" class="h-4 w-4" />
            </button>
            <button type="button" @click="scroll(1)" aria-label="পরের"
                    class="absolute -right-3 top-[28%] hidden h-9 w-9 items-center justify-center
                           rounded-full border border-line bg-surface shadow-card hover:text-brand lg:flex">
                <x-ui.icon name="chevron-right" class="h-4 w-4" />
            </button>
        </div>
    </section>
@endif
