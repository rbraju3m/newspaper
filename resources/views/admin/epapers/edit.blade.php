@extends('layouts.admin')
@section('title', 'ই-পেপার সম্পাদনা')
@section('heading', 'ই-পেপার সম্পাদনা')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="lg:col-span-8">
        {{-- Drag order is the page order, and page one is the cover. --}}
        <form method="POST" action="{{ route('admin.epapers.pages.reorder', $epaper->id) }}"
              x-data="{
                  pages: {{ Js::from($epaper->pages->map(fn ($p) => [
                      'id' => $p->id,
                      'url' => asset('storage/'.($p->thumbnail ?: $p->image)),
                      'section' => $p->section,
                  ])) }},
                  drag: null,
                  start(i) { this.drag = i },
                  over(i) {
                      if (this.drag === null || this.drag === i) return
                      const moved = this.pages.splice(this.drag, 1)[0]
                      this.pages.splice(i, 0, moved)
                      this.drag = i
                  },
              }"
              class="rounded-xl border border-line bg-surface p-4">
            @csrf

            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-headline text-sm font-bold text-ink">
                    পৃষ্ঠা (<span class="lat">@bn($epaper->pages->count())</span>)
                </h2>
                <button type="submit" x-show="pages.length > 1"
                        class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white">
                    ক্রম সংরক্ষণ
                </button>
            </div>

            <ul class="grid gap-3 grid-cols-3 sm:grid-cols-5" @dragover.prevent>
                <template x-for="(page, i) in pages" :key="page.id">
                    <li draggable="true"
                        @dragstart="start(i)"
                        @dragover.prevent="over(i)"
                        @dragend="drag = null"
                        class="cursor-move overflow-hidden rounded-lg border border-line bg-canvas">
                        <input type="hidden" name="pages[]" :value="page.id">
                        <img :src="page.url" alt="" loading="lazy" decoding="async"
                             class="aspect-[3/4] w-full object-cover">
                        <p class="truncate px-1.5 py-1 text-center text-2xs text-muted">
                            <span class="lat" x-text="i + 1"></span><span x-text="page.section ? ' · ' + page.section : ''"></span>
                        </p>
                    </li>
                </template>
            </ul>

            @if ($epaper->pages->isEmpty())
                <x-ui.empty-state icon="copy" title="এখনো কোনো পৃষ্ঠা নেই"
                                  description="ডান পাশ থেকে পৃষ্ঠার ছবি আপলোড করুন।" />
            @endif
        </form>

        @if ($epaper->pages->isNotEmpty())
            <div class="mt-4 space-y-2">
                @foreach ($epaper->pages as $page)
                    <div x-data="{ open: false }" class="rounded-xl border border-line bg-surface">
                        <div class="flex items-center gap-3 p-3">
                            <img src="{{ asset('storage/'.($page->thumbnail ?: $page->image)) }}" alt=""
                                 width="36" height="48" loading="lazy"
                                 class="h-12 w-9 shrink-0 rounded object-cover">
                            <p class="min-w-0 flex-1 truncate text-sm text-body">
                                পৃষ্ঠা <span class="lat">@bn($page->page_number)</span>
                                @if ($page->section) — {{ $page->section }} @endif
                            </p>
                            <button type="button" @click="open = !open"
                                    class="rounded-md p-1.5 text-muted hover:text-brand focus-visible:text-brand">
                                <x-ui.icon name="settings" class="h-4 w-4" />
                            </button>
                        </div>

                        <form x-show="open" x-cloak x-collapse method="POST"
                              action="{{ route('admin.epapers.pages.update', $page->id) }}"
                              class="space-y-2 border-t border-line p-3">
                            @csrf @method('PUT')
                            <input name="section" value="{{ $page->section }}" maxlength="60"
                                   placeholder="বিভাগ — যেমন প্রথম পাতা, খেলা"
                                   class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                            <div class="flex gap-2">
                                <button type="submit" class="rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">
                                    সংরক্ষণ
                                </button>
                                {{-- Its own form, rather than a second _method
                                     field winning by DOM order. --}}
                                <button type="submit" form="drop-page-{{ $page->id }}"
                                        onclick="return confirm('পৃষ্ঠাটি সরাতে চান?')"
                                        class="rounded border border-brand px-3 py-1.5 text-xs font-semibold text-brand">
                                    সরান
                                </button>
                            </div>
                        </form>

                        <form id="drop-page-{{ $page->id }}" method="POST" class="hidden"
                              action="{{ route('admin.epapers.pages.destroy', $page->id) }}">
                            @csrf @method('DELETE')
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <section class="space-y-4 lg:col-span-4">
        <form method="POST" enctype="multipart/form-data"
              action="{{ route('admin.epapers.pages.store', $epaper->id) }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf
            <h2 class="font-headline text-sm font-bold text-ink">পৃষ্ঠা যোগ করুন</h2>

            <input type="file" name="files[]" multiple required accept="image/jpeg,image/png,image/webp"
                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            <input name="section" maxlength="60" placeholder="বিভাগ (সব পৃষ্ঠার জন্য)"
                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            <p class="text-2xs text-muted">ফাইলগুলি যে ক্রমে বেছে নেবেন, সেই ক্রমেই পৃষ্ঠা নম্বর বসবে।</p>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                আপলোড করুন
            </button>
        </form>

        <form method="POST" enctype="multipart/form-data"
              action="{{ route('admin.epapers.pdf.store', $epaper->id) }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf
            <h2 class="font-headline text-sm font-bold text-ink">পুরো সংখ্যার পিডিএফ</h2>

            @if ($epaper->pdf)
                <a href="{{ asset('storage/'.$epaper->pdf) }}" target="_blank" rel="noopener"
                   class="block truncate text-xs text-link">বর্তমান পিডিএফ দেখুন</a>
            @endif

            <input type="file" name="pdf" required accept="application/pdf"
                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                পিডিএফ আপলোড
            </button>
        </form>

        <form method="POST" action="{{ route('admin.epapers.update', $epaper->id) }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf @method('PUT')
            <h2 class="font-headline text-sm font-bold text-ink">সংখ্যার তথ্য</h2>

            <div>
                <label for="e-date" class="mb-1.5 block text-xs font-semibold text-ink">তারিখ</label>
                <input id="e-date" type="date" name="date" required value="{{ $epaper->date->toDateString() }}"
                       class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
            </div>

            <div>
                <label for="e-edition" class="mb-1.5 block text-xs font-semibold text-ink">সংস্করণ</label>
                <select id="e-edition" name="edition"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                    @foreach ($editions as $key => $label)
                        <option value="{{ $key }}" @selected($epaper->edition === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <label class="flex items-center gap-2 text-sm text-body">
                <input type="checkbox" name="is_published" value="1" @checked($epaper->is_published)
                       class="rounded accent-[var(--color-brand)]"> প্রকাশিত
            </label>

            <div class="flex gap-2">
                <button type="submit" class="flex-1 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                    সংরক্ষণ
                </button>
                <button type="submit" form="drop-epaper"
                        onclick="return confirm('পুরো সংখ্যাটি মুছে ফেলতে চান?')"
                        class="rounded-lg border border-brand px-4 py-2 text-sm font-semibold text-brand">
                    মুছুন
                </button>
            </div>
        </form>

        <form id="drop-epaper" method="POST" class="hidden"
              action="{{ route('admin.epapers.destroy', $epaper->id) }}">
            @csrf @method('DELETE')
        </form>
    </section>
</div>
@endsection
