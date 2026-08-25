@extends('layouts.admin')
@section('title', 'লাইভ আপডেট')
@section('heading', 'লাইভ: '.Str::limit($article->title, 40))

@section('actions')
    <a href="{{ route('admin.articles.edit', $article) }}"
       class="rounded-lg border border-line px-4 py-2 text-sm font-semibold text-ink">খবর সম্পাদনা</a>
    @if ($article->isVisible())
        <a href="{{ $article->url }}" target="_blank"
           class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">সাইটে দেখুন</a>
    @endif
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    {{-- Composer stays at the top: adding the next update is the only thing
         anyone does on this screen during a running story. --}}
    <section class="lg:col-span-5">
        <form method="POST" action="{{ route('admin.live.store', $article) }}"
              class="sticky top-20 space-y-3 rounded-xl border border-line bg-surface p-5">
            @csrf
            <h2 class="font-headline text-base font-bold text-ink">নতুন আপডেট</h2>

            <x-form.input name="headline" label="শিরোনাম" />

            <div>
                <label for="l-body" class="mb-1.5 block text-sm font-semibold text-ink">বিবরণ</label>
                <textarea id="l-body" name="body" rows="6" required maxlength="8000"
                          placeholder="<p>আপডেটের বিবরণ…</p>"
                          class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2
                                 font-mono text-sm text-ink">{{ old('body') }}</textarea>
                @error('body')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
            </div>

            <x-form.input name="embed_url" label="ভিডিও লিংক" type="url" />

            <div class="flex flex-wrap gap-4 text-sm text-body">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_pinned" value="1" class="rounded accent-[var(--color-brand)]">
                    উপরে পিন করুন
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_key" value="1" class="rounded accent-[var(--color-brand)]">
                    “এক নজরে”-তে দেখান
                </label>
            </div>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                আপডেট প্রকাশ করুন
            </button>
        </form>
    </section>

    <section class="lg:col-span-7">
        <ol class="space-y-3">
            @forelse ($entries as $entry)
                <li x-data="{ edit: false }" class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                @if ($entry->is_pinned)
                                    <span class="rounded bg-brand px-1.5 py-0.5 font-bold text-white">পিন</span>
                                @endif
                                @if ($entry->is_key)
                                    <span class="rounded bg-amber-400 px-1.5 py-0.5 font-bold text-amber-950">এক নজরে</span>
                                @endif
                                <time class="text-muted">{{ $entry->time_label }} · {{ App\Support\Bangla::ago($entry->published_at) }}</time>
                                @if ($entry->author)<span class="text-muted">— {{ $entry->author->name }}</span>@endif
                            </div>

                            @if ($entry->headline)
                                <h3 class="mt-1 font-headline text-sm font-bold text-ink">{{ $entry->headline }}</h3>
                            @endif
                            <div class="prose-editorial mt-1 text-sm text-body">{!! $entry->body !!}</div>
                        </div>

                        <button type="button" @click="edit = !edit" class="rounded-md p-1.5 text-muted hover:text-brand">
                            <x-ui.icon name="settings" class="h-4 w-4" />
                        </button>
                    </div>

                    <form x-show="edit" x-cloak x-collapse method="POST"
                          action="{{ route('admin.live.update', $entry) }}"
                          class="mt-3 space-y-2 border-t border-line pt-3">
                        @csrf @method('PUT')
                        <input name="headline" value="{{ $entry->headline }}" placeholder="শিরোনাম"
                               class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                        <textarea name="body" rows="4" required
                                  class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 font-mono text-xs">{{ $entry->body }}</textarea>
                        <div class="flex flex-wrap gap-3 text-xs text-body">
                            <label class="flex items-center gap-1.5">
                                <input type="checkbox" name="is_pinned" value="1" @checked($entry->is_pinned)
                                       class="rounded accent-[var(--color-brand)]"> পিন
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input type="checkbox" name="is_key" value="1" @checked($entry->is_key)
                                       class="rounded accent-[var(--color-brand)]"> এক নজরে
                            </label>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">সংরক্ষণ</button>
                            <button type="submit" form="drop-entry-{{ $entry->id }}"
                                    onclick="return confirm('আপডেটটি মুছে ফেলতে চান?')"
                                    class="rounded border border-brand px-3 py-1.5 text-xs font-semibold text-brand">মুছুন</button>
                        </div>
                    </form>

                    <form id="drop-entry-{{ $entry->id }}" method="POST"
                          action="{{ route('admin.live.destroy', $entry) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                </li>
            @empty
                <x-ui.empty-state title="এখনো কোনো আপডেট নেই"
                                  message="বাঁ পাশের ফর্ম দিয়ে প্রথম আপডেটটি যোগ করুন।" />
            @endforelse
        </ol>

        <div class="mt-4">{{ $entries->links() }}</div>
    </section>
</div>
@endsection
