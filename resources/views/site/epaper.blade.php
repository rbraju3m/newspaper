@extends('layouts.site')
@section('title', 'ই-পেপার — '.config('site.name_bn'))

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b-2 border-brand pb-3">
            <div>
                <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">ই-পেপার</h1>
                @if ($epaper)
                    <p class="mt-1 text-base text-muted">
                        @bndate($epaper->date), {{ App\Support\Bangla::weekday($epaper->date) }}
                    </p>
                @endif
            </div>

            @if ($epaper?->pdf)
                <a href="{{ asset('storage/'.$epaper->pdf) }}" target="_blank" rel="noopener"
                   class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    পিডিএফ ডাউনলোড
                </a>
            @endif
        </header>

        @if ($epaper && $epaper->pages->isNotEmpty())
            <div class="grid gap-6 lg:grid-cols-12">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:col-span-9">
                    @foreach ($epaper->pages as $page)
                        <a href="{{ asset('storage/'.$page->image) }}" target="_blank" rel="noopener"
                           class="group block">
                            <figure class="relative overflow-hidden rounded-lg border border-line bg-surface">
                                <img src="{{ asset('storage/'.($page->thumbnail ?: $page->image)) }}"
                                     alt="পৃষ্ঠা {{ $page->page_number }}" loading="lazy"
                                     class="w-full transition duration-500 group-hover:scale-[1.02]">
                            </figure>
                            <p class="mt-1.5 text-center text-xs font-medium text-body">
                                @if ($page->section) {{ $page->section }} — @endif
                                পৃষ্ঠা @bn($page->page_number)
                            </p>
                        </a>
                    @endforeach
                </div>

                <aside class="lg:col-span-3">
                    <x-ui.section-header title="আগের সংখ্যা" />
                    <ul class="space-y-1.5">
                        @foreach ($recent as $issue)
                            <li>
                                <a href="{{ route('epaper.show', $issue->date->toDateString()) }}"
                                   @class([
                                       'block rounded-lg px-3 py-2 text-sm transition',
                                       'bg-brand text-white' => $epaper->id === $issue->id,
                                       'text-body hover:bg-surface-2' => $epaper->id !== $issue->id,
                                   ])>
                                    {{ App\Support\Bangla::date($issue->date) }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </aside>
            </div>
        @else
            <x-ui.empty-state icon="newspaper" title="ই-পেপার এখনো প্রকাশিত হয়নি"
                              message="আজকের সংস্করণ শীঘ্রই পাওয়া যাবে।" />
        @endif
    </div>
@endsection
