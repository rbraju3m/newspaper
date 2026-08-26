@extends('layouts.admin')
@section('title', $gallery->title)
@section('heading', 'গ্যালারি সম্পাদনা')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    {{-- Images: the drag order is the running order on the public page. --}}
    <section class="lg:col-span-8">
        <form method="POST" action="{{ route('admin.galleries.images.reorder', $gallery->id) }}"
              x-data="{
                  images: {{ Js::from($gallery->images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url, 'caption' => $i->caption])) }},
                  drag: null,
                  start(i) { this.drag = i },
                  over(i) {
                      if (this.drag === null || this.drag === i) return
                      const moved = this.images.splice(this.drag, 1)[0]
                      this.images.splice(i, 0, moved)
                      this.drag = i
                  },
              }"
              class="rounded-xl border border-line bg-surface p-4">
            @csrf

            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-headline text-sm font-bold text-ink">
                    ছবি (<span class="lat">@bn($gallery->images->count())</span>)
                </h2>
                <button type="submit" x-show="images.length > 1"
                        class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white">
                    ক্রম সংরক্ষণ
                </button>
            </div>

            <p class="mb-3 text-xs text-muted" x-show="images.length > 1">
                ছবি টেনে সাজান। প্রথম ছবিটিই গ্যালারির প্রচ্ছদ।
            </p>

            <ul class="grid gap-3 sm:grid-cols-3" @dragover.prevent>
                <template x-for="(image, i) in images" :key="image.id">
                    <li draggable="true"
                        @dragstart="start(i)"
                        @dragover.prevent="over(i)"
                        @dragend="drag = null"
                        class="cursor-move overflow-hidden rounded-lg border border-line bg-canvas">
                        <input type="hidden" name="images[]" :value="image.id">
                        <img :src="image.url" alt="" loading="lazy" decoding="async"
                             class="aspect-[4/3] w-full object-cover">
                        <p class="truncate px-2 py-1.5 text-2xs text-muted" x-text="image.caption || '—'"></p>
                    </li>
                </template>
            </ul>

            @if ($gallery->images->isEmpty())
                <x-ui.empty-state icon="camera" title="এখনো কোনো ছবি নেই"
                                  description="ডান পাশ থেকে ছবি আপলোড করুন।" />
            @endif
        </form>

        {{-- Per-image caption and removal, outside the reorder form. --}}
        @if ($gallery->images->isNotEmpty())
            <div class="mt-4 space-y-2">
                @foreach ($gallery->images as $image)
                    <div x-data="{ open: false }" class="rounded-xl border border-line bg-surface">
                        <div class="flex items-center gap-3 p-3">
                            <img src="{{ $image->url }}" alt="" width="56" height="42" loading="lazy"
                                 class="h-10 w-14 shrink-0 rounded object-cover">
                            <p class="min-w-0 flex-1 truncate text-sm text-body">{{ $image->caption ?: '—' }}</p>
                            <button type="button" @click="open = !open"
                                    class="rounded-md p-1.5 text-muted hover:text-brand focus-visible:text-brand">
                                <x-ui.icon name="settings" class="h-4 w-4" />
                            </button>
                        </div>

                        <form x-show="open" x-cloak x-collapse method="POST"
                              action="{{ route('admin.galleries.images.update', $image->id) }}"
                              class="space-y-2 border-t border-line p-3">
                            @csrf @method('PUT')
                            <input name="caption" value="{{ $image->caption }}" placeholder="ক্যাপশন" maxlength="500"
                                   class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                            <input name="credit" value="{{ $image->credit }}" placeholder="কৃতজ্ঞতা" maxlength="255"
                                   class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                            <div class="flex gap-2">
                                <button type="submit" class="rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">
                                    সংরক্ষণ
                                </button>
                                {{-- Targets its own form below rather than relying
                                     on a second _method field winning by DOM order. --}}
                                <button type="submit" form="drop-image-{{ $image->id }}"
                                        onclick="return confirm('ছবিটি সরাতে চান?')"
                                        class="rounded border border-brand px-3 py-1.5 text-xs font-semibold text-brand">
                                    সরান
                                </button>
                            </div>
                        </form>

                        <form id="drop-image-{{ $image->id }}" method="POST" class="hidden"
                              action="{{ route('admin.galleries.images.destroy', $image->id) }}">
                            @csrf @method('DELETE')
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="space-y-4 lg:col-span-4">
        <form method="POST" enctype="multipart/form-data"
              action="{{ route('admin.galleries.images.store', $gallery->id) }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf
            <h2 class="font-headline text-sm font-bold text-ink">ছবি যোগ করুন</h2>

            <input type="file" name="files[]" multiple required accept="image/jpeg,image/png,image/webp"
                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            <input name="credit" placeholder="কৃতজ্ঞতা (সব ছবির জন্য)" maxlength="255"
                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                আপলোড করুন
            </button>
        </form>

        <form method="POST" action="{{ route('admin.galleries.update', $gallery->id) }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf @method('PUT')
            <h2 class="font-headline text-sm font-bold text-ink">গ্যালারির তথ্য</h2>

            <x-form.input name="title" label="শিরোনাম" :value="$gallery->title" required />

            <div>
                <label for="g-desc" class="mb-1.5 block text-xs font-semibold text-ink">বিবরণ</label>
                <textarea id="g-desc" name="description" rows="3" maxlength="2000"
                          class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">{{ $gallery->description }}</textarea>
            </div>

            <div>
                <label for="g-cat" class="mb-1.5 block text-xs font-semibold text-ink">বিভাগ</label>
                <select id="g-cat" name="category_id"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                    <option value="">—</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected($gallery->category_id === $c->id)>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="g-status" class="mb-1.5 block text-xs font-semibold text-ink">অবস্থা</label>
                <select id="g-status" name="status"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                    <option value="draft" @selected($gallery->status !== 'published')>খসড়া</option>
                    <option value="published" @selected($gallery->status === 'published')>প্রকাশিত</option>
                </select>
            </div>

            <div>
                <label for="g-at" class="mb-1.5 block text-xs font-semibold text-ink">প্রকাশের সময়</label>
                <input id="g-at" type="datetime-local" name="published_at"
                       value="{{ $gallery->published_at?->format('Y-m-d\TH:i') }}"
                       class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            </div>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                    সংরক্ষণ
                </button>
                <button type="submit" form="drop-gallery"
                        onclick="return confirm('পুরো গ্যালারিটি মুছে ফেলতে চান?')"
                        class="rounded-lg border border-brand px-4 py-2 text-sm font-semibold text-brand">
                    মুছুন
                </button>
            </div>
        </form>

        <form id="drop-gallery" method="POST" class="hidden"
              action="{{ route('admin.galleries.destroy', $gallery->id) }}">
            @csrf @method('DELETE')
        </form>
    </section>
</div>
@endsection
