@extends('layouts.site')

@section('title', 'আর্কাইভ — '.App\Support\Bangla::date($date).' — '.config('site.name_bn'))

@section('content')
    <div class="mx-auto max-w-site px-4 py-5 lg:py-7">
        <header class="mb-6 border-b-2 border-brand pb-3">
            <h1 class="font-headline text-3xl font-bold text-ink lg:text-4xl">আর্কাইভ</h1>
            <p class="mt-1.5 text-base text-muted">
                @bndate($date), {{ App\Support\Bangla::weekday($date) }}
            </p>
        </header>

        <form action="{{ route('archive') }}" method="GET"
              class="mb-7 flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-4">
            <div>
                <label for="a-date" class="mb-1 block text-xs font-semibold text-ink">তারিখ</label>
                <input id="a-date" type="date" name="date" value="{{ $date->toDateString() }}"
                       max="{{ now()->toDateString() }}"
                       class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink lat">
            </div>
            <div>
                <label for="a-cat" class="mb-1 block text-xs font-semibold text-ink">বিভাগ</label>
                <select id="a-cat" name="category"
                        class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    <option value="">সব বিভাগ</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->slug }}" @selected($category?->slug === $c->slug)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                দেখুন
            </button>

            <div class="ml-auto flex items-center gap-2 text-sm">
                <a href="{{ route('archive', ['date' => $date->copy()->subDay()->toDateString(), 'category' => $category?->slug]) }}"
                   class="flex items-center gap-1 rounded-lg border border-line px-3 py-2 hover:border-brand">
                    <x-ui.icon name="chevron-left" class="h-3.5 w-3.5" /> আগের দিন
                </a>
                @if (! $date->isToday())
                    <a href="{{ route('archive', ['date' => $date->copy()->addDay()->toDateString(), 'category' => $category?->slug]) }}"
                       class="flex items-center gap-1 rounded-lg border border-line px-3 py-2 hover:border-brand">
                        পরের দিন <x-ui.icon name="chevron-right" class="h-3.5 w-3.5" />
                    </a>
                @endif
            </div>
        </form>

        <div class="grid gap-x-8 gap-y-5 lg:grid-cols-2">
            @include('site.partials.article-list-items')
        </div>

        @if ($articles->isEmpty())
            <x-ui.empty-state icon="calendar" title="এই দিনে কোনো খবর নেই"
                              message="অন্য তারিখ বেছে নিন।" />
        @endif

        <div class="mt-8">{{ $articles->links() }}</div>
    </div>
@endsection
