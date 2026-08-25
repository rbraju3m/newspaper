@extends('layouts.admin')
@section('title', 'স্ট্যাটিক পাতা')
@section('heading', 'স্ট্যাটিক পাতা')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="space-y-2 lg:col-span-7">
        @forelse ($pages as $page)
            <div x-data="{ edit: false }" class="rounded-xl border border-line bg-surface p-3">
                <div class="flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-ink">{{ $page->title }}</p>
                        <p class="lat truncate text-xs text-muted">/page/{{ $page->slug }}</p>
                    </div>
                    @unless ($page->is_active)
                        <span class="rounded bg-slate-200 px-1.5 py-0.5 text-2xs text-slate-700">নিষ্ক্রিয়</span>
                    @endunless
                    <a href="{{ route('page.show', $page->slug) }}" target="_blank"
                       class="rounded-md p-1.5 text-muted hover:text-brand">
                        <x-ui.icon name="eye" class="h-4 w-4" />
                    </a>
                    <button type="button" @click="edit = !edit" class="rounded-md p-1.5 text-muted hover:text-brand">
                        <x-ui.icon name="settings" class="h-4 w-4" />
                    </button>
                </div>

                <form x-show="edit" x-cloak x-collapse method="POST"
                      action="{{ route('admin.pages.update', $page) }}"
                      class="mt-3 space-y-2 border-t border-line pt-3">
                    @csrf @method('PUT')
                    <input name="title" value="{{ $page->title }}" required
                           class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                    <input name="slug" value="{{ $page->slug }}" required
                           class="lat w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">
                    <textarea name="body" rows="10"
                              class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 font-mono text-xs">{{ $page->body }}</textarea>
                    <label class="flex items-center gap-2 text-sm text-body">
                        <input type="checkbox" name="is_active" value="1" @checked($page->is_active)
                               class="rounded accent-[var(--color-brand)]"> সক্রিয়
                    </label>
                    <div class="flex gap-2">
                        <button type="submit" class="rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">সংরক্ষণ</button>
                        <button type="submit" form="drop-page-{{ $page->id }}"
                                onclick="return confirm('পাতাটি মুছে ফেলতে চান?')"
                                class="rounded border border-brand px-3 py-1.5 text-xs font-semibold text-brand">মুছুন</button>
                    </div>
                </form>

                <form id="drop-page-{{ $page->id }}" method="POST"
                      action="{{ route('admin.pages.destroy', $page) }}" class="hidden">
                    @csrf @method('DELETE')
                </form>
            </div>
        @empty
            <x-ui.empty-state icon="copy" title="কোনো পাতা নেই" />
        @endforelse
    </section>

    <section class="lg:col-span-5">
        <form method="POST" action="{{ route('admin.pages.store') }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-5">
            @csrf
            <h2 class="font-headline text-base font-bold text-ink">নতুন পাতা</h2>
            <x-form.input name="title" label="শিরোনাম" :required="true" />
            <x-form.input name="slug" label="স্লাগ" :required="true" hint="যেমন: about, contact" />
            <div>
                <label for="p-body" class="mb-1.5 block text-sm font-semibold text-ink">বিষয়বস্তু (HTML)</label>
                <textarea id="p-body" name="body" rows="8"
                          class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 font-mono text-xs text-ink"></textarea>
            </div>
            <label class="flex items-center gap-2 text-sm text-body">
                <input type="checkbox" name="is_active" value="1" checked class="rounded accent-[var(--color-brand)]"> সক্রিয়
            </label>
            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                পাতা তৈরি করুন
            </button>
        </form>
    </section>
</div>
@endsection
