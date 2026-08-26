@extends('layouts.admin')
@section('title', 'ই-পেপার')
@section('heading', 'ই-পেপার')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="space-y-2 lg:col-span-8">
        @forelse ($epapers as $epaper)
            <div class="flex items-center gap-3 rounded-xl border border-line bg-surface p-3">
                <div class="h-16 w-12 shrink-0 overflow-hidden rounded bg-surface-2">
                    @if ($epaper->cover)
                        <img src="{{ asset('storage/'.$epaper->cover) }}" alt=""
                             width="48" height="64" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink">@bndate($epaper->date)</p>
                    <p class="truncate text-xs text-muted">
                        {{ $editions[$epaper->edition] ?? $epaper->edition }}
                        · <span class="lat">@bn($epaper->pages_count)</span> পৃষ্ঠা
                        @if ($epaper->pdf) · পিডিএফ আছে @endif
                    </p>
                </div>

                @unless ($epaper->is_published)
                    <span class="rounded bg-slate-200 px-1.5 py-0.5 text-2xs text-slate-700">অপ্রকাশিত</span>
                @endunless

                @if ($epaper->is_published)
                    <a href="{{ route('epaper.show', $epaper->date->toDateString()) }}" target="_blank"
                       class="rounded-md p-1.5 text-muted hover:text-brand focus-visible:text-brand">
                        <x-ui.icon name="eye" class="h-4 w-4" />
                    </a>
                @endif

                <a href="{{ route('admin.epapers.edit', $epaper->id) }}"
                   class="rounded-md p-1.5 text-muted hover:text-brand focus-visible:text-brand">
                    <x-ui.icon name="settings" class="h-4 w-4" />
                </a>
            </div>
        @empty
            <x-ui.empty-state icon="copy" title="কোনো সংখ্যা নেই"
                              description="ডান পাশের ফর্ম থেকে আজকের সংখ্যাটি তৈরি করুন।" />
        @endforelse

        {{ $epapers->links() }}
    </section>

    <section class="lg:col-span-4">
        <form method="POST" action="{{ route('admin.epapers.store') }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf
            <h2 class="font-headline text-sm font-bold text-ink">নতুন সংখ্যা</h2>

            <div>
                <label for="e-date" class="mb-1.5 block text-xs font-semibold text-ink">তারিখ</label>
                <input id="e-date" type="date" name="date" required value="{{ old('date', now()->toDateString()) }}"
                       class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            </div>

            <div>
                <label for="e-edition" class="mb-1.5 block text-xs font-semibold text-ink">সংস্করণ</label>
                <select id="e-edition" name="edition"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                    @foreach ($editions as $key => $label)
                        <option value="{{ $key }}" @selected(old('edition') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                তৈরি করুন
            </button>
        </form>
    </section>
</div>
@endsection
