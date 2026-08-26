@extends('layouts.admin')
@section('title', 'ফটো গ্যালারি')
@section('heading', 'ফটো গ্যালারি')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="space-y-3 lg:col-span-8">
        <form method="GET" action="{{ route('admin.galleries.index') }}" class="flex gap-2">
            <input name="q" value="{{ request('q') }}" placeholder="শিরোনাম খুঁজুন"
                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            <button type="submit" class="rounded-lg border border-line-strong px-4 py-2 text-sm font-semibold text-body">
                খুঁজুন
            </button>
        </form>

        @forelse ($galleries as $gallery)
            <div class="flex items-center gap-3 rounded-xl border border-line bg-surface p-3">
                <div class="h-14 w-20 shrink-0 overflow-hidden rounded-lg bg-surface-2">
                    @if ($gallery->cover)
                        <img src="{{ asset('storage/'.$gallery->cover) }}" alt=""
                             width="80" height="56" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-ink">{{ $gallery->title }}</p>
                    <p class="truncate text-xs text-muted">
                        <span class="lat">@bn($gallery->images_count)</span> ছবি
                        @if ($gallery->category) · {{ $gallery->category->name }} @endif
                        @if ($gallery->published_at) · @bndate($gallery->published_at) @endif
                    </p>
                </div>

                @if ($gallery->status !== 'published')
                    <span class="rounded bg-slate-200 px-1.5 py-0.5 text-2xs text-slate-700">খসড়া</span>
                @endif

                @if ($gallery->status === 'published')
                    <a href="{{ route('photo.show', $gallery->slug) }}" target="_blank"
                       class="rounded-md p-1.5 text-muted hover:text-brand focus-visible:text-brand">
                        <x-ui.icon name="eye" class="h-4 w-4" />
                    </a>
                @endif

                <a href="{{ route('admin.galleries.edit', $gallery->id) }}"
                   class="rounded-md p-1.5 text-muted hover:text-brand focus-visible:text-brand">
                    <x-ui.icon name="settings" class="h-4 w-4" />
                </a>
            </div>
        @empty
            <x-ui.empty-state icon="camera" title="কোনো গ্যালারি নেই"
                              description="ডান পাশের ফর্ম থেকে প্রথম গ্যালারিটি তৈরি করুন।" />
        @endforelse

        {{ $galleries->links() }}
    </section>

    <section class="lg:col-span-4">
        <form method="POST" action="{{ route('admin.galleries.store') }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf
            <h2 class="font-headline text-sm font-bold text-ink">নতুন গ্যালারি</h2>

            <x-form.input name="title" label="শিরোনাম" required />

            <div>
                <label for="g-desc" class="mb-1.5 block text-xs font-semibold text-ink">বিবরণ (ঐচ্ছিক)</label>
                <textarea id="g-desc" name="description" rows="3" maxlength="2000"
                          class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">{{ old('description') }}</textarea>
            </div>

            <div>
                <label for="g-cat" class="mb-1.5 block text-xs font-semibold text-ink">বিভাগ</label>
                <select id="g-cat" name="category_id"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="g-status" class="mb-1.5 block text-xs font-semibold text-ink">অবস্থা</label>
                <select id="g-status" name="status"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                    <option value="draft">খসড়া</option>
                    <option value="published">প্রকাশিত</option>
                </select>
            </div>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                তৈরি করুন
            </button>
        </form>
    </section>
</div>
@endsection
