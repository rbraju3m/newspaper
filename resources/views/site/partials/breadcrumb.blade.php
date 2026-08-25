@props(['items'])

{{-- Visible breadcrumb + matching JSON-LD, so the trail shows in search
     results as well as on the page. --}}
<nav aria-label="ব্রেডক্রাম্ব" class="mb-3">
    <ol class="flex flex-wrap items-center gap-1.5 text-xs text-muted">
        <li><a href="{{ route('home') }}" class="hover:text-brand">প্রচ্ছদ</a></li>
        @foreach ($items as $item)
            <li aria-hidden="true"><x-ui.icon name="chevron-right" class="h-3 w-3" /></li>
            <li>
                @if (! $loop->last && ($item['url'] ?? null))
                    <a href="{{ $item['url'] }}" class="hover:text-brand">{{ $item['label'] }}</a>
                @else
                    <span class="font-medium text-body" aria-current="page">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => collect($items)->values()->map(fn ($item, $i) => array_filter([
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => $item['label'],
        'item' => $item['url'] ?? null,
    ]))->all(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
