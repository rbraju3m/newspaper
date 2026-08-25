@extends('layouts.admin')
@section('title', 'ট্যাগ ও বিষয়')
@section('heading', 'ট্যাগ ও বিশেষ আয়োজন')

@section('content')
<div class="grid gap-5 lg:grid-cols-2">

    {{-- Topics --}}
    <section>
        <h2 class="mb-3 font-headline text-base font-bold text-ink">বিশেষ আয়োজন (টপিক)</h2>

        <div class="space-y-2">
            @foreach ($topics as $topic)
                <div x-data="{ edit: false }" class="rounded-xl border border-line bg-surface p-3">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 shrink-0 rounded-full" style="background: {{ $topic->color }}"></span>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">{{ $topic->name }}</span>
                        @if ($topic->is_trending)
                            <span class="rounded bg-brand px-1.5 py-0.5 text-2xs font-bold text-white">ট্রেন্ডিং</span>
                        @endif
                        <span class="lat text-xs text-muted">@bn($topic->articles_count)</span>
                        <button type="button" @click="edit = !edit" class="rounded-md p-1 text-muted hover:text-brand">
                            <x-ui.icon name="settings" class="h-4 w-4" />
                        </button>
                    </div>

                    <form x-show="edit" x-cloak x-collapse method="POST"
                          action="{{ route('admin.taxonomy.topics.update', $topic) }}" class="mt-3 space-y-2">
                        @csrf @method('PUT')
                        <input name="name" value="{{ $topic->name }}" required
                               class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                        <input name="slug" value="{{ $topic->slug }}"
                               class="lat w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">
                        <textarea name="description" rows="2" placeholder="বিবরণ"
                                  class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">{{ $topic->description }}</textarea>
                        <div class="flex items-center gap-2">
                            <input type="color" name="color" value="{{ $topic->color }}"
                                   class="h-8 w-12 rounded border border-line-strong bg-canvas">
                            <input type="number" name="position" value="{{ $topic->position }}" min="0"
                                   class="lat w-16 rounded border border-line-strong bg-canvas px-2 py-1 text-xs">
                            <label class="flex items-center gap-1.5 text-xs text-body">
                                <input type="checkbox" name="is_active" value="1" @checked($topic->is_active)
                                       class="rounded accent-[var(--color-brand)]"> সক্রিয়
                            </label>
                            <label class="flex items-center gap-1.5 text-xs text-body">
                                <input type="checkbox" name="is_trending" value="1" @checked($topic->is_trending)
                                       class="rounded accent-[var(--color-brand)]"> ট্রেন্ডিং
                            </label>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded bg-brand px-3 py-1 text-xs font-semibold text-white">সংরক্ষণ</button>
                            <button type="submit" form="drop-topic-{{ $topic->id }}"
                                    onclick="return confirm('এই বিষয়টি মুছে ফেলতে চান?')"
                                    class="rounded border border-brand px-3 py-1 text-xs font-semibold text-brand">মুছুন</button>
                        </div>
                    </form>

                    <form id="drop-topic-{{ $topic->id }}" method="POST"
                          action="{{ route('admin.taxonomy.topics.destroy', $topic) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('admin.taxonomy.topics.store') }}"
              class="mt-4 space-y-3 rounded-xl border border-line bg-surface p-4">
            @csrf
            <h3 class="text-sm font-bold text-ink">নতুন বিষয়</h3>
            <x-form.input name="name" label="নাম" :required="true" />
            <x-form.input name="slug" label="স্লাগ" hint="ইংরেজি অক্ষর, সংখ্যা ও হাইফেন।" />
            <div class="flex items-center gap-3">
                <input type="color" name="color" value="#C8102E" class="h-10 w-16 rounded-lg border border-line-strong">
                <label class="flex items-center gap-2 text-sm text-body">
                    <input type="checkbox" name="is_active" value="1" checked class="rounded accent-[var(--color-brand)]"> সক্রিয়
                </label>
                <label class="flex items-center gap-2 text-sm text-body">
                    <input type="checkbox" name="is_trending" value="1" class="rounded accent-[var(--color-brand)]"> ট্রেন্ডিং
                </label>
            </div>
            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">যুক্ত করুন</button>
        </form>
    </section>

    {{-- Tags --}}
    <section>
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2 class="font-headline text-base font-bold text-ink">ট্যাগ</h2>
            <form method="GET" class="flex gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="ট্যাগ খুঁজুন"
                       class="rounded-lg border border-line-strong bg-surface px-3 py-1.5 text-sm">
                <button type="submit" class="rounded-lg border border-line px-3 py-1.5 text-sm">খুঁজুন</button>
            </form>
        </div>

        <div class="overflow-hidden rounded-xl border border-line bg-surface">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-line">
                    @forelse ($tags as $tag)
                        <tr x-data="{ edit: false }">
                            <td class="px-3 py-2">
                                <span x-show="!edit" class="text-ink">{{ $tag->name }}</span>
                                <form x-show="edit" x-cloak method="POST"
                                      action="{{ route('admin.taxonomy.tags.update', $tag) }}" class="flex gap-1">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $tag->name }}" required
                                           class="min-w-0 flex-1 rounded border border-line-strong bg-canvas px-2 py-1 text-sm">
                                    <button type="submit" class="rounded bg-brand px-2 py-1 text-xs text-white">✓</button>
                                </form>
                            </td>
                            <td class="lat px-3 py-2 text-end text-xs text-muted">@bn($tag->articles_count)</td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1">
                                    <button type="button" @click="edit = !edit"
                                            class="rounded-md p-1 text-muted hover:text-brand">
                                        <x-ui.icon name="settings" class="h-3.5 w-3.5" />
                                    </button>
                                    <form method="POST" action="{{ route('admin.taxonomy.tags.destroy', $tag) }}"
                                          onsubmit="return confirm('ট্যাগটি মুছে ফেলতে চান?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="rounded-md p-1 text-muted hover:text-brand">
                                            <x-ui.icon name="close" class="h-3.5 w-3.5" />
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="px-3 py-8 text-center text-muted">কোনো ট্যাগ নেই।</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $tags->links() }}</div>
    </section>
</div>
@endsection
