@props(['block', 'data'])

@if ($data->isNotEmpty())
    <section>
        <x-ui.section-header title="ফটো গ্যালারি" :href="route('photo.index')" color="#4F46E5" />

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($data as $gallery)
                <a href="{{ route('photo.show', $gallery) }}" class="group relative block">
                    <figure class="aspect-square overflow-hidden rounded-lg bg-surface-2">
                        @if ($gallery->cover)
                            <img src="{{ asset('storage/'.$gallery->cover) }}" alt=""
                                 loading="lazy" decoding="async" sizes="(min-width:1024px) 200px, 45vw"
                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                        @endif
                    </figure>

                    {{-- Gradient scrim, not a flat overlay — keeps the caption
                         readable over any photo without dimming the whole image. --}}
                    <span class="absolute inset-x-0 bottom-0 rounded-b-lg bg-gradient-to-t
                                 from-black/85 via-black/40 to-transparent p-2.5">
                        <span class="line-clamp-2 text-xs font-semibold leading-snug text-white">
                            {{ $gallery->title }}
                        </span>
                        <span class="mt-0.5 flex items-center gap-1 text-2xs text-white/75">
                            <x-ui.icon name="camera" class="h-3 w-3" />
                            @bn($gallery->images_count) ছবি
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>
@endif
