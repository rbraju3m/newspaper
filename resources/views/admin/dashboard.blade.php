@extends('layouts.admin')
@section('title', 'ড্যাশবোর্ড')
@section('heading', 'ড্যাশবোর্ড')

@section('actions')
    <a href="{{ route('admin.articles.create') }}"
       class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
        নতুন খবর
    </a>
@endsection

@section('content')
    {{-- Things waiting on a human come first — this is the desk's to-do list,
         not a vanity metrics wall. --}}
    @php
        $todo = collect([
            ['in_review', 'পর্যালোচনার অপেক্ষায়', route('admin.articles.index', ['status' => 'review'])],
            ['pending_comments', 'অপেক্ষমাণ মন্তব্য', route('admin.comments.index', ['status' => 'pending'])],
            ['reported_comments', 'রিপোর্ট করা মন্তব্য', route('admin.comments.index', ['reported' => 1, 'status' => 'all'])],
            ['scheduled_due', 'সময় পেরিয়ে যাওয়া নির্ধারিত', route('admin.articles.index', ['status' => 'scheduled'])],
        ])->filter(fn ($row) => ($needsAttention[$row[0]] ?? 0) > 0);
    @endphp

    @if ($todo->isNotEmpty())
        <section class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
            <h2 class="font-headline text-base font-bold text-ink">আপনার নজর প্রয়োজন</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($todo as [$key, $label, $href])
                    <a href="{{ $href }}"
                       class="flex items-center gap-2 rounded-lg border border-amber-300 bg-surface px-3 py-2
                              text-sm font-medium text-ink transition hover:border-brand dark:border-amber-900">
                        <span class="lat rounded bg-brand px-1.5 py-0.5 text-2xs font-bold text-white">
                            @bn($needsAttention[$key])
                        </span>
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Counts --}}
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([
            ['প্রকাশিত', $counts['published'], 'published'],
            ['খসড়া', $counts['draft'], 'draft'],
            ['পর্যালোচনায়', $counts['review'], 'review'],
            ['নির্ধারিত', $counts['scheduled'], 'scheduled'],
            ['পাঠক', $counts['readers'], null],
            ['আজকের ভিউ', $counts['views_today'], null],
        ] as [$label, $value, $status])
            <a @if($status) href="{{ route('admin.articles.index', ['status' => $status]) }}" @endif
               class="rounded-xl border border-line bg-surface p-4 transition {{ $status ? 'hover:border-brand' : '' }}">
                <p class="font-headline text-2xl font-bold text-ink">@bncount($value)</p>
                <p class="mt-0.5 text-xs text-muted">{{ $label }}</p>
            </a>
        @endforeach
    </section>

    <div class="mt-6 grid gap-6 lg:grid-cols-12">
        {{-- Publishing trend --}}
        <section class="rounded-xl border border-line bg-surface p-5 lg:col-span-7">
            <h2 class="font-headline text-base font-bold text-ink">গত ১৪ দিনে প্রকাশিত</h2>

            @php $max = max(1, max($chart)); @endphp
            <div class="mt-4 flex h-32 items-end gap-1.5">
                @foreach ($chart as $date => $count)
                    <div class="group flex flex-1 flex-col items-center gap-1">
                        <span class="lat text-2xs text-muted opacity-0 transition group-hover:opacity-100">
                            @bn($count)
                        </span>
                        {{-- min-height keeps zero days visible as a hairline
                             instead of vanishing entirely --}}
                        <div class="w-full rounded-t bg-brand/80 transition group-hover:bg-brand"
                             style="height: {{ max(2, round($count / $max * 100)) }}%"
                             title="{{ $date }}: {{ $count }}"></div>
                        <span class="lat text-2xs text-muted">{{ \Illuminate\Support\Carbon::parse($date)->format('j') }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Top stories --}}
        <section class="rounded-xl border border-line bg-surface p-5 lg:col-span-5">
            <h2 class="font-headline text-base font-bold text-ink">এই সপ্তাহে সর্বাধিক পঠিত</h2>
            <ol class="mt-3 space-y-2.5">
                @forelse ($topStories as $i => $story)
                    <li class="flex gap-2.5">
                        <span class="lat w-5 shrink-0 text-sm font-bold {{ $i < 3 ? 'text-brand' : 'text-line-strong' }}">
                            @bn($i + 1)
                        </span>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('admin.articles.edit', $story) }}"
                               class="block truncate text-sm font-medium text-ink hover:text-brand">
                                {{ $story->title }}
                            </a>
                            <p class="lat text-2xs text-muted">
                                @bncount($story->views) ভিউ · @bn($story->comments_count) মন্তব্য
                            </p>
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-muted">এখনো যথেষ্ট তথ্য নেই।</li>
                @endforelse
            </ol>
        </section>
    </div>

    {{-- Recent activity --}}
    <section class="mt-6 rounded-xl border border-line bg-surface">
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <h2 class="font-headline text-base font-bold text-ink">সাম্প্রতিক কাজ</h2>
            <a href="{{ route('admin.articles.index') }}" class="text-sm font-medium text-link hover:text-brand">
                সব খবর
            </a>
        </div>

        <div class="divide-y divide-line">
            @forelse ($recent as $article)
                <div class="flex items-center gap-3 px-5 py-3">
                    <span class="h-8 w-1 shrink-0 rounded-full"
                          style="background: {{ $article->category?->color ?? '#ccc' }}"></span>
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('admin.articles.edit', $article) }}"
                           class="block truncate text-sm font-medium text-ink hover:text-brand">
                            {{ $article->title }}
                        </a>
                        <p class="truncate text-xs text-muted">
                            {{ $article->author?->name ?? '—' }} ·
                            {{ App\Support\Bangla::ago($article->updated_at) }}
                        </p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-2xs font-semibold {{ $article->status->color() }}">
                        {{ $article->status->label() }}
                    </span>
                </div>
            @empty
                <p class="px-5 py-6 text-center text-sm text-muted">এখনো কোনো খবর নেই।</p>
            @endforelse
        </div>
    </section>
@endsection
