@extends('layouts.admin')
@section('title', 'বিভাগ')
@section('heading', 'বিভাগ')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="lg:col-span-7">
        <div class="overflow-hidden rounded-xl border border-line bg-surface">
            <table class="w-full text-sm">
                <thead class="border-b border-line bg-surface-2">
                    <tr class="text-xs uppercase tracking-wide text-muted">
                        <th class="px-4 py-2.5 text-start font-semibold">নাম</th>
                        <th class="px-4 py-2.5 text-start font-semibold">পথ</th>
                        <th class="px-4 py-2.5 text-end font-semibold">খবর</th>
                        <th class="px-4 py-2.5 text-center font-semibold">মেনু</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($categories as $category)
                        <tr x-data="{ edit: false }" class="align-top">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2 {{ $category->parent_id ? 'ps-5' : '' }}">
                                    <span class="h-3 w-3 shrink-0 rounded-full" style="background: {{ $category->color }}"></span>
                                    <span class="font-medium text-ink">{{ $category->name }}</span>
                                    @unless ($category->is_active)
                                        <span class="rounded bg-slate-200 px-1.5 text-2xs text-slate-700">নিষ্ক্রিয়</span>
                                    @endunless
                                </div>

                                <form x-show="edit" x-cloak method="POST"
                                      action="{{ route('admin.categories.update', $category) }}" class="mt-2 space-y-2">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $category->name }}" required
                                           class="w-full rounded border border-line-strong bg-canvas px-2 py-1 text-sm">
                                    <input name="slug" value="{{ $category->slug }}"
                                           class="lat w-full rounded border border-line-strong bg-canvas px-2 py-1 text-xs">
                                    <select name="parent_id" class="w-full rounded border border-line-strong bg-canvas px-2 py-1 text-xs">
                                        <option value="">— শীর্ষ পর্যায় —</option>
                                        @foreach ($categories->whereNull('parent_id') as $p)
                                            @continue($p->id === $category->id)
                                            <option value="{{ $p->id }}" @selected($category->parent_id === $p->id)>{{ $p->name }}</option>
                                        @endforeach
                                    </select>
                                    <div class="flex gap-2">
                                        <input type="color" name="color" value="{{ $category->color }}"
                                               class="h-8 w-12 rounded border border-line-strong bg-canvas">
                                        <input type="number" name="position" value="{{ $category->position }}" min="0"
                                               class="lat w-16 rounded border border-line-strong bg-canvas px-2 py-1 text-xs">
                                    </div>
                                    <div class="flex flex-wrap gap-3 text-xs text-body">
                                        @foreach ([['is_active','সক্রিয়'],['show_in_nav','মেনুতে'],['show_in_footer','ফুটারে']] as [$f,$l])
                                            <label class="flex items-center gap-1.5">
                                                <input type="checkbox" name="{{ $f }}" value="1" @checked($category->$f)
                                                       class="rounded accent-[var(--color-brand)]"> {{ $l }}
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="rounded bg-brand px-3 py-1 text-xs font-semibold text-white">সংরক্ষণ</button>
                                        <button type="button" @click="edit = false" class="rounded border border-line px-3 py-1 text-xs">বাতিল</button>
                                    </div>
                                </form>
                            </td>
                            <td class="lat px-4 py-2.5 text-xs text-muted">{{ $category->path }}</td>
                            <td class="lat px-4 py-2.5 text-end text-xs text-muted">@bn($category->articles_count)</td>
                            <td class="px-4 py-2.5 text-center">
                                @if ($category->show_in_nav)
                                    <x-ui.icon name="check" class="mx-auto h-4 w-4 text-green-600" />
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex justify-end gap-1">
                                    <button type="button" @click="edit = !edit"
                                            class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-brand">
                                        <x-ui.icon name="settings" class="h-4 w-4" />
                                    </button>
                                    <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                                          onsubmit="return confirm('এই বিভাগটি মুছে ফেলতে চান?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-brand">
                                            <x-ui.icon name="close" class="h-4 w-4" />
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="lg:col-span-5">
        <form method="POST" action="{{ route('admin.categories.store') }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-5">
            @csrf
            <h2 class="font-headline text-base font-bold text-ink">নতুন বিভাগ</h2>

            <x-form.input name="name" label="নাম (বাংলা)" :required="true" />
            <x-form.input name="name_en" label="নাম (ইংরেজি)" />
            <x-form.input name="slug" label="স্লাগ" hint="খালি রাখলে ইংরেজি নাম থেকে তৈরি হবে।" />

            <div>
                <label for="f-parent" class="mb-1.5 block text-sm font-semibold text-ink">মূল বিভাগ</label>
                <select id="f-parent" name="parent_id"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    <option value="">— শীর্ষ পর্যায় —</option>
                    @foreach ($categories->whereNull('parent_id') as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex gap-3">
                <div>
                    <label for="f-color" class="mb-1.5 block text-sm font-semibold text-ink">রঙ</label>
                    <input id="f-color" type="color" name="color" value="#C8102E"
                           class="h-10 w-20 rounded-lg border border-line-strong bg-canvas">
                </div>
                <div class="flex-1">
                    <label for="f-pos" class="mb-1.5 block text-sm font-semibold text-ink">ক্রম</label>
                    <input id="f-pos" type="number" name="position" value="0" min="0"
                           class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                </div>
            </div>

            <div class="flex flex-wrap gap-4 text-sm text-body">
                @foreach ([['is_active','সক্রিয়',true],['show_in_nav','মেনুতে দেখান',true],['show_in_footer','ফুটারে দেখান',true]] as [$f,$l,$d])
                    <label class="flex items-center gap-2">
                        <input type="checkbox" name="{{ $f }}" value="1" @checked($d)
                               class="rounded border-line-strong accent-[var(--color-brand)]"> {{ $l }}
                    </label>
                @endforeach
            </div>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                বিভাগ যুক্ত করুন
            </button>
        </form>
    </section>
</div>
@endsection
