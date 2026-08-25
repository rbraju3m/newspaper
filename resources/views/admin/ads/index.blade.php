@extends('layouts.admin')
@section('title', 'বিজ্ঞাপন')
@section('heading', 'বিজ্ঞাপন')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="lg:col-span-7">
        <div class="space-y-2">
            @forelse ($ads as $ad)
                <div x-data="{ edit: false }" class="rounded-xl border border-line bg-surface p-3">
                    <div class="flex items-center gap-3">
                        @if ($ad->asset_url)
                            <img src="{{ $ad->asset_url }}" alt="" class="h-10 w-16 shrink-0 rounded object-cover">
                        @else
                            <span class="flex h-10 w-16 shrink-0 items-center justify-center rounded bg-surface-2 text-2xs text-muted">
                                নেই
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ $ad->title }}</p>
                            <p class="lat truncate text-xs text-muted">
                                {{ $ad->position }} ·
                                @bncount($ad->impressions) ইম্প্রেশন ·
                                @bncount($ad->clicks) ক্লিক ({{ $ad->ctr() }}%)
                            </p>
                        </div>

                        <span class="shrink-0 rounded-full px-2 py-0.5 text-2xs font-semibold
                                     {{ $ad->is_active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-700' }}">
                            {{ $ad->is_active ? 'সক্রিয়' : 'নিষ্ক্রিয়' }}
                        </span>

                        <button type="button" @click="edit = !edit" class="rounded-md p-1.5 text-muted hover:text-brand">
                            <x-ui.icon name="settings" class="h-4 w-4" />
                        </button>
                    </div>

                    <form x-show="edit" x-cloak x-collapse method="POST"
                          action="{{ route('admin.ads.update', $ad) }}" enctype="multipart/form-data"
                          class="mt-3 space-y-2 border-t border-line pt-3">
                        @csrf @method('PUT')
                        <input name="title" value="{{ $ad->title }}" required placeholder="শিরোনাম"
                               class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">

                        <div class="grid gap-2 sm:grid-cols-2">
                            <select name="position" class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                @foreach ($slots as $key => $dim)
                                    <option value="{{ $key }}" @selected($ad->position === $key)>
                                        {{ $key }} ({{ $dim['w'] }}×{{ $dim['h'] }})
                                    </option>
                                @endforeach
                            </select>
                            <select name="type" class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                @foreach (['image' => 'ছবি', 'html' => 'HTML', 'adsense' => 'AdSense'] as $v => $l)
                                    <option value="{{ $v }}" @selected($ad->type === $v)>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>

                        <input name="url" value="{{ $ad->url }}" placeholder="ক্লিক করলে যে ঠিকানায় যাবে"
                               class="lat w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                        <input type="file" name="file" accept="image/*"
                               class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">

                        <div class="grid gap-2 sm:grid-cols-3">
                            <input type="datetime-local" name="starts_at"
                                   value="{{ $ad->starts_at?->format('Y-m-d\TH:i') }}"
                                   class="lat rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">
                            <input type="datetime-local" name="ends_at"
                                   value="{{ $ad->ends_at?->format('Y-m-d\TH:i') }}"
                                   class="lat rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">
                            <input type="number" name="priority" value="{{ $ad->priority }}" min="0" placeholder="অগ্রাধিকার"
                                   class="lat rounded border border-line-strong bg-canvas px-2 py-1.5 text-xs">
                        </div>

                        <label class="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" name="is_active" value="1" @checked($ad->is_active)
                                   class="rounded accent-[var(--color-brand)]"> সক্রিয়
                        </label>

                        <div class="flex gap-2">
                            <button type="submit" class="rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">সংরক্ষণ</button>
                            <button type="submit" form="drop-ad-{{ $ad->id }}"
                                    onclick="return confirm('বিজ্ঞাপনটি মুছে ফেলতে চান?')"
                                    class="rounded border border-brand px-3 py-1.5 text-xs font-semibold text-brand">মুছুন</button>
                        </div>
                    </form>

                    <form id="drop-ad-{{ $ad->id }}" method="POST"
                          action="{{ route('admin.ads.destroy', $ad) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                </div>
            @empty
                <x-ui.empty-state icon="eye" title="কোনো বিজ্ঞাপন নেই" />
            @endforelse
        </div>
    </section>

    <section class="lg:col-span-5">
        <form method="POST" action="{{ route('admin.ads.store') }}" enctype="multipart/form-data"
              class="space-y-3 rounded-xl border border-line bg-surface p-5">
            @csrf
            <h2 class="font-headline text-base font-bold text-ink">নতুন বিজ্ঞাপন</h2>

            <x-form.input name="title" label="শিরোনাম" :required="true" />

            <div>
                <label for="a-pos" class="mb-1.5 block text-sm font-semibold text-ink">স্থান</label>
                <select id="a-pos" name="position" required
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    @foreach ($slots as $key => $dim)
                        <option value="{{ $key }}">{{ $key }} ({{ $dim['w'] }}×{{ $dim['h'] }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="a-type" class="mb-1.5 block text-sm font-semibold text-ink">ধরন</label>
                <select id="a-type" name="type" required
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    <option value="image">ছবি</option>
                    <option value="html">HTML</option>
                    <option value="adsense">AdSense</option>
                </select>
            </div>

            <x-form.input name="url" label="লিংক" type="url" />

            <div>
                <label for="a-file" class="mb-1.5 block text-sm font-semibold text-ink">ছবি</label>
                <input id="a-file" type="file" name="file" accept="image/*"
                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <x-form.input name="starts_at" label="শুরু" type="datetime-local" />
                <x-form.input name="ends_at" label="শেষ" type="datetime-local" />
            </div>

            <label class="flex items-center gap-2 text-sm text-body">
                <input type="checkbox" name="is_active" value="1" checked class="rounded accent-[var(--color-brand)]"> সক্রিয়
            </label>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                যুক্ত করুন
            </button>
        </form>
    </section>
</div>
@endsection
