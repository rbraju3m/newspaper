@extends('layouts.admin')
@section('title', 'মিডিয়া')
@section('heading', 'মিডিয়া লাইব্রেরি')

@section('content')
<div x-data="{ uploading: false, error: '' }">
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-1 gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="ফাইলের নাম খুঁজুন"
                   class="min-w-0 flex-1 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm text-ink">
            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">খুঁজুন</button>
        </form>

        <label class="cursor-pointer rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            ছবি আপলোড
            <input type="file" accept="image/*" multiple class="sr-only"
                   @change="
                      error = ''; uploading = true;
                      const files = Array.from($event.target.files);
                      Promise.all(files.map(f => {
                          const b = new FormData(); b.append('file', f);
                          return fetch('{{ route('admin.media.store') }}', {
                              method: 'POST',
                              headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                              body: b,
                          }).then(r => r.ok ? r.json() : r.json().then(d => Promise.reject(d)));
                      })).then(() => window.location.reload())
                        .catch(d => { error = d.message || 'আপলোড ব্যর্থ হয়েছে।'; })
                        .finally(() => uploading = false);
                   ">
        </label>
    </div>

    <p x-show="uploading" x-cloak class="mb-3 text-sm text-muted">আপলোড হচ্ছে…</p>
    <p x-show="error" x-cloak x-text="error" class="mb-3 text-sm text-brand"></p>

    @if ($media->isEmpty())
        <x-ui.empty-state icon="camera" title="লাইব্রেরি খালি"
                          message="উপরের বোতাম দিয়ে ছবি আপলোড করুন।" />
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
            @foreach ($media as $item)
                <figure class="group overflow-hidden rounded-lg border border-line bg-surface"
                        x-data="{ open: false }">
                    <div class="relative aspect-square bg-surface-2">
                        <img src="{{ $item->conversion('thumb') }}" alt="{{ $item->alt }}" loading="lazy"
                             class="h-full w-full object-cover">
                        <button type="button" @click="open = true"
                                class="absolute inset-0 flex items-center justify-center bg-black/0
                                       opacity-0 transition group-hover:bg-black/40 group-hover:opacity-100">
                            <span class="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-ink">বিস্তারিত</span>
                        </button>
                    </div>
                    <figcaption class="p-2">
                        <p class="truncate text-xs font-medium text-ink">{{ $item->filename }}</p>
                        <p class="lat text-2xs text-muted">
                            @bn($item->width)×@bn($item->height) · {{ round($item->size / 1024) }}KB
                        </p>
                    </figcaption>

                    {{-- Detail / edit dialog --}}
                    <div x-show="open" x-cloak @click.self="open = false"
                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-surface p-5">
                            <img src="{{ $item->url }}" alt="" class="w-full rounded-lg">

                            <form method="POST" action="{{ route('admin.media.update', $item) }}" class="mt-4 space-y-3">
                                @csrf @method('PATCH')
                                <input name="alt" value="{{ $item->alt }}" placeholder="বিকল্প টেক্সট (alt)"
                                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                                <input name="caption" value="{{ $item->caption }}" placeholder="ক্যাপশন"
                                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                                <input name="credit" value="{{ $item->credit }}" placeholder="কৃতজ্ঞতা"
                                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">

                                <div class="flex items-center gap-2 rounded-lg bg-surface-2 p-2">
                                    <input readonly value="{{ $item->path }}"
                                           class="lat min-w-0 flex-1 bg-transparent text-xs text-muted"
                                           onclick="this.select()">
                                    <span class="shrink-0 text-2xs text-muted">পাথ</span>
                                </div>

                                <div class="flex gap-2">
                                    <button type="submit"
                                            class="flex-1 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                                        সংরক্ষণ
                                    </button>
                                    <button type="button" @click="open = false"
                                            class="rounded-lg border border-line px-4 py-2 text-sm font-semibold text-ink">
                                        বন্ধ
                                    </button>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.media.destroy', $item) }}" class="mt-2"
                                  onsubmit="return confirm('ছবিটি স্থায়ীভাবে মুছে ফেলতে চান? যেসব খবরে এটি ব্যবহৃত হয়েছে সেখানে ছবি হারিয়ে যাবে।')">
                                @csrf @method('DELETE')
                                <button type="submit" class="w-full rounded-lg border border-brand px-4 py-2 text-sm font-semibold text-brand">
                                    মুছে ফেলুন
                                </button>
                            </form>
                        </div>
                    </div>
                </figure>
            @endforeach
        </div>

        <div class="mt-4">{{ $media->links() }}</div>
    @endif
</div>
@endsection
