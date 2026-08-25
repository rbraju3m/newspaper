@props([
    'article',
    'variant' => 'standard',   // hero | feature | standard | list | compact | rail
    'showExcerpt' => null,
    'showCategory' => true,
    'showMeta' => true,
    'eager' => false,          // set on the LCP image only
])

@php
    // One component, six shapes. Keeping them together means a headline renders
    // with the same clamping, badge and meta rules everywhere on the site.
    $c = [
        'hero' => [
            'wrap'  => 'group block',
            'figure'=> 'relative aspect-[16/9] overflow-hidden rounded-lg bg-surface-2',
            'title' => 'mt-3 font-headline text-2xl font-bold leading-snug lg:text-4xl clamp-3',
            'excerpt'=> 'mt-2 text-base text-body clamp-2 lg:text-lg',
            'sizes' => '(min-width: 1024px) 840px, 100vw',
        ],
        'feature' => [
            'wrap'  => 'group block',
            'figure'=> 'relative aspect-[16/9] overflow-hidden rounded-lg bg-surface-2',
            'title' => 'mt-2.5 font-headline text-lg font-bold leading-snug lg:text-xl clamp-3',
            'excerpt'=> 'mt-1.5 text-sm text-body clamp-2',
            'sizes' => '(min-width: 1024px) 400px, 50vw',
        ],
        'standard' => [
            'wrap'  => 'group block',
            'figure'=> 'relative aspect-[16/9] overflow-hidden rounded-md bg-surface-2',
            'title' => 'mt-2 font-headline text-base font-semibold leading-snug lg:text-lg clamp-3',
            'excerpt'=> 'mt-1 text-sm text-muted clamp-2',
            'sizes' => '(min-width: 1024px) 300px, 50vw',
        ],
        // Horizontal row: thumbnail right, headline left. Used in sidebars and
        // "more from this section" lists.
        'list' => [
            'wrap'  => 'group flex items-start gap-3',
            'figure'=> 'relative aspect-[4/3] w-24 shrink-0 overflow-hidden rounded-md bg-surface-2 sm:w-28',
            'title' => 'font-headline text-sm font-semibold leading-snug lg:text-base clamp-3',
            'excerpt'=> 'mt-1 text-xs text-muted clamp-2',
            'sizes' => '120px',
        ],
        // Text-only, for ranked lists where the number carries the hierarchy.
        'compact' => [
            'wrap'  => 'group block',
            'figure'=> null,
            'title' => 'font-headline text-sm font-semibold leading-snug lg:text-base clamp-3',
            'excerpt'=> null,
            'sizes' => null,
        ],
        'rail' => [
            'wrap'  => 'group block w-[260px] shrink-0 sm:w-[300px]',
            'figure'=> 'relative aspect-[16/9] overflow-hidden rounded-md bg-surface-2',
            'title' => 'mt-2 font-headline text-base font-semibold leading-snug clamp-3',
            'excerpt'=> null,
            'sizes' => '300px',
        ],
    ][$variant] ?? [];

    $showExcerpt = $showExcerpt ?? in_array($variant, ['hero', 'feature'], true);
    $badge = $article->type->badgeIcon();
@endphp

<article {{ $attributes->merge(['class' => $c['wrap']]) }}>
    <a href="{{ $article->url }}" class="contents">

        @if ($c['figure'] ?? null)
            <figure class="{{ $c['figure'] }}">
                @if ($article->image_url)
                    {{-- srcset only when the linked Media actually has a
                         derivative ladder. `sizes` without `srcset` is inert,
                         so both are emitted together or not at all. --}}
                    <img src="{{ $article->image_url }}"
                         alt=""
                         @if ($article->image_srcset)
                             srcset="{{ $article->image_srcset }}"
                             sizes="{{ $c['sizes'] }}"
                         @endif
                         loading="{{ $eager ? 'eager' : 'lazy' }}"
                         fetchpriority="{{ $eager ? 'high' : 'auto' }}"
                         decoding="async"
                         class="h-full w-full object-cover transition duration-500
                                group-hover:scale-[1.03]">
                @else
                    <div class="flex h-full w-full items-center justify-center text-muted/40">
                        <x-ui.icon name="newspaper" class="h-8 w-8" />
                    </div>
                @endif

                {{-- Media affordance: readers need to know it is a video before
                     they click, not after. --}}
                @if ($badge === 'play')
                    <span class="absolute inset-0 flex items-center justify-center">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full
                                     bg-black/55 text-white backdrop-blur-sm transition
                                     group-hover:bg-brand">
                            <x-ui.icon name="play" class="ml-0.5 h-5 w-5" stroke="0" />
                        </span>
                    </span>
                    @if ($article->video_duration)
                        <span class="absolute bottom-1.5 right-1.5 rounded bg-black/75 px-1.5 py-0.5
                                     text-2xs font-medium text-white lat">
                            {{ gmdate($article->video_duration >= 3600 ? 'H:i:s' : 'i:s', $article->video_duration) }}
                        </span>
                    @endif
                @elseif ($badge === 'camera')
                    <span class="absolute bottom-1.5 right-1.5 flex items-center gap-1 rounded
                                 bg-black/75 px-1.5 py-0.5 text-2xs font-medium text-white">
                        <x-ui.icon name="camera" class="h-3 w-3" /> ফটো
                    </span>
                @endif

                @if ($article->is_premium)
                    <span class="absolute left-1.5 top-1.5 rounded bg-amber-400 px-1.5 py-0.5
                                 text-2xs font-bold text-amber-950">প্রিমিয়াম</span>
                @endif
            </figure>
        @endif

        <div class="{{ $variant === 'list' ? 'min-w-0 flex-1' : '' }}">
            @if ($showCategory && $article->category)
                <span class="text-2xs font-bold uppercase tracking-wide"
                      style="color: {{ $article->category->color }}">
                    {{ $article->kicker ?: $article->category->name }}
                </span>
            @endif

            <h3 class="{{ $c['title'] }} transition group-hover:text-brand">
                {{ $article->title }}
            </h3>

            @if ($showExcerpt && $article->excerpt && ($c['excerpt'] ?? null))
                <p class="{{ $c['excerpt'] }}">{{ $article->excerpt }}</p>
            @endif

            @if ($showMeta)
                <div class="mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-muted">
                    @if ($article->is_new)
                        <span class="font-semibold text-brand">নতুন</span>
                    @endif

                    <time datetime="{{ $article->published_at?->toIso8601String() }}">
                        {{ $article->published_ago }}
                    </time>

                    @if ($article->comments_count)
                        <span class="flex items-center gap-1">
                            <x-ui.icon name="comment" class="h-3 w-3" />
                            @bncount($article->comments_count)
                        </span>
                    @endif
                </div>
            @endif
        </div>
    </a>
</article>
