@extends('layouts.admin')
@section('title', 'প্রচ্ছদ সাজান')
@section('heading', 'প্রচ্ছদ সাজান')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <div class="lg:col-span-8">
        <form method="POST" action="{{ route('admin.layout.reorder') }}"
              x-data="{
                  main: {{ Js::from($main->map(fn ($b) => ['id' => $b->id, 'label' => $b->type->label(), 'heading' => $b->heading(), 'limit' => $b->limit, 'active' => $b->is_active])) }},
                  sidebar: {{ Js::from($sidebar->map(fn ($b) => ['id' => $b->id, 'label' => $b->type->label(), 'heading' => $b->heading(), 'limit' => $b->limit, 'active' => $b->is_active])) }},
                  drag: null,
                  start(col, i) { this.drag = { col, i }; },
                  over(col, i) {
                      if (!this.drag) return;
                      const from = this[this.drag.col];
                      const to = this[col];
                      if (this.drag.col === col && this.drag.i === i) return;
                      const moved = from.splice(this.drag.i, 1)[0];
                      to.splice(i, 0, moved);
                      this.drag = { col, i };
                  },
              }">
            @csrf

            <div class="mb-3 flex items-center justify-between">
                <p class="text-sm text-muted">ব্লক টেনে সাজান, তারপর সংরক্ষণ করুন।</p>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                    সাজানো সংরক্ষণ
                </button>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                @foreach ([['main', 'মূল কলাম'], ['sidebar', 'সাইডবার']] as [$col, $label])
                    <div class="rounded-xl border border-line bg-surface p-3">
                        <h2 class="mb-2 font-headline text-sm font-bold text-ink">{{ $label }}</h2>

                        <ul class="min-h-24 space-y-2" @dragover.prevent>
                            <template x-for="(block, i) in {{ $col }}" :key="block.id">
                                <li draggable="true"
                                    @dragstart="start('{{ $col }}', i)"
                                    @dragover.prevent="over('{{ $col }}', i)"
                                    @dragend="drag = null"
                                    class="cursor-move rounded-lg border border-line bg-canvas p-2.5"
                                    :class="!block.active && 'opacity-50'">
                                    <input type="hidden" :name="'{{ $col }}[]'" :value="block.id">
                                    <div class="flex items-center gap-2">
                                        <span class="text-muted">⠿</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-medium text-ink" x-text="block.label"></p>
                                            <p class="truncate text-2xs text-muted"
                                               x-text="(block.heading || '—') + ' · ' + block.limit + ' টি'"></p>
                                        </div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>
                @endforeach
            </div>
        </form>

        {{-- Per-block settings, outside the reorder form --}}
        <div class="mt-5 space-y-2">
            @foreach ($main->concat($sidebar) as $block)
                <div x-data="{ open: false }" class="rounded-xl border border-line bg-surface">
                    <button type="button" @click="open = !open"
                            class="flex w-full items-center gap-3 px-4 py-3 text-start">
                        <span class="rounded bg-surface-2 px-2 py-0.5 text-2xs font-semibold text-body">
                            {{ $block->column === 'main' ? 'মূল' : 'সাইড' }}
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-ink">
                            {{ $block->type->label() }}
                            @if ($block->heading())<span class="text-muted">— {{ $block->heading() }}</span>@endif
                        </span>
                        <x-ui.icon name="chevron-down" class="h-4 w-4 text-muted" ::class="open && 'rotate-180'" />
                    </button>

                    <form x-show="open" x-cloak x-collapse method="POST"
                          action="{{ route('admin.layout.update', $block) }}"
                          class="space-y-3 border-t border-line p-4">
                        @csrf @method('PUT')

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-ink">ধরন</label>
                                <select name="type" class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                                    @foreach ($types as $t)
                                        <option value="{{ $t->value }}" @selected($block->type === $t)>{{ $t->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-ink">শিরোনাম (ঐচ্ছিক)</label>
                                <input name="title" value="{{ $block->title }}"
                                       class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-ink">বিভাগ</label>
                                <select name="category_id" class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                                    <option value="">—</option>
                                    @foreach ($categories as $c)
                                        <option value="{{ $c->id }}" @selected($block->category_id === $c->id)>{{ $c->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-ink">বিশেষ আয়োজন</label>
                                <select name="topic_id" class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                                    <option value="">—</option>
                                    @foreach ($topics as $t)
                                        <option value="{{ $t->id }}" @selected($block->topic_id === $t->id)>{{ $t->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-ink">কতটি খবর</label>
                                <input type="number" name="limit" value="{{ $block->limit }}" min="1" max="24"
                                       class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1.5 block text-xs font-semibold text-ink">কলাম</label>
                                <select name="column" class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm">
                                    <option value="main" @selected($block->column === 'main')>মূল কলাম</option>
                                    <option value="sidebar" @selected($block->column === 'sidebar')>সাইডবার</option>
                                </select>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-sm text-body">
                            <input type="checkbox" name="is_active" value="1" @checked($block->is_active)
                                   class="rounded border-line-strong accent-[var(--color-brand)]"> সক্রিয়
                        </label>

                        <div class="flex gap-2">
                            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                                সংরক্ষণ
                            </button>
                            {{-- Targets its own form below, rather than
                                 relying on a second _method field overriding
                                 the PUT one by DOM order. --}}
                            <button type="submit" form="drop-block-{{ $block->id }}"
                                    onclick="return confirm('এই ব্লকটি সরাতে চান?')"
                                    class="rounded-lg border border-brand px-4 py-2 text-sm font-semibold text-brand">
                                সরান
                            </button>
                        </div>
                    </form>

                    <form id="drop-block-{{ $block->id }}" method="POST"
                          action="{{ route('admin.layout.destroy', $block) }}" class="hidden">
                        @csrf @method('DELETE')
                    </form>
                </div>
            @endforeach
        </div>
    </div>

    <section class="lg:col-span-4">
        <form method="POST" action="{{ route('admin.layout.store') }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-5">
            @csrf
            <h2 class="font-headline text-base font-bold text-ink">নতুন ব্লক</h2>

            <div>
                <label for="n-type" class="mb-1.5 block text-sm font-semibold text-ink">ধরন</label>
                <select id="n-type" name="type" required
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    @foreach ($types as $t)
                        <option value="{{ $t->value }}">{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="n-cat" class="mb-1.5 block text-sm font-semibold text-ink">বিভাগ</label>
                <select id="n-cat" name="category_id"
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    <option value="">—</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
                @error('category_id')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="n-limit" class="mb-1.5 block text-sm font-semibold text-ink">কতটি</label>
                    <input id="n-limit" type="number" name="limit" value="5" min="1" max="24" required
                           class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                </div>
                <div>
                    <label for="n-col" class="mb-1.5 block text-sm font-semibold text-ink">কলাম</label>
                    <select id="n-col" name="column" class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                        <option value="main">মূল কলাম</option>
                        <option value="sidebar">সাইডবার</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                ব্লক যুক্ত করুন
            </button>
        </form>
    </section>
</div>
@endsection
