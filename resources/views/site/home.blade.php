@extends('layouts.site')

@section('title', config('site.name_bn').' — '.config('site.description'))
@section('description', config('site.description'))
@section('canonical', route('home'))

@push('schema')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'NewsMediaOrganization',
    'name' => config('site.name_bn'),
    'alternateName' => config('site.name_en'),
    'url' => route('home'),
    'logo' => asset('images/logo.png'),
    'sameAs' => array_values(array_filter(config('site.social'))),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}
</script>
@endpush

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
        <div class="grid gap-8 lg:grid-cols-12 lg:gap-10">

            {{-- Main column: editor-ordered blocks --}}
            <div class="space-y-9 lg:col-span-8">
                @foreach ($mainBlocks as $entry)
                    @includeIf($entry['block']->view(), [
                        'block' => $entry['block'],
                        'data' => $entry['data'],
                    ])
                @endforeach
            </div>

            {{-- Sidebar sticks so the most-read list stays reachable through a
                 long front page, which none of the reference sites do. --}}
            <aside class="lg:col-span-4">
                <div class="space-y-6 lg:sticky lg:top-20">
                    @foreach ($sidebarBlocks as $entry)
                        @includeIf($entry['block']->view(), [
                            'block' => $entry['block'],
                            'data' => $entry['data'],
                        ])
                    @endforeach
                </div>
            </aside>
        </div>
    </div>
@endsection
