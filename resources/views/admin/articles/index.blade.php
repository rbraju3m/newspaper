@extends('layouts.admin')
@section('title', 'খবর')
@section('heading', 'খবর')

@section('actions')
    <a href="{{ route('admin.articles.create') }}"
       class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
        নতুন খবর
    </a>
@endsection

@section('content')
    <form method="GET" class="mb-4 grid gap-2 rounded-xl border border-line bg-surface p-3 sm:grid-cols-2 lg:grid-cols-6">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="শিরোনাম খুঁজুন"
               class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink lg:col-span-2">

        <select name="status" class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
            <option value="">সব অবস্থা</option>
            @foreach ($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>
            @endforeach
        </select>

        <select name="type" class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
            <option value="">সব ধরন</option>
            @foreach ($types as $t)
                <option value="{{ $t->value }}" @selected(request('type') === $t->value)>{{ $t->label() }}</option>
            @endforeach
        </select>

        <select name="category" class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
            <option value="">সব বিভাগ</option>
            @foreach ($categories as $c)
                <option value="{{ $c->id }}" @selected(request('category') == $c->id)>
                    {{ $c->parent_id ? '— ' : '' }}{{ $c->name }}
                </option>
            @endforeach
        </select>

        <div class="flex gap-2">
            <button type="submit" class="flex-1 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">
                ফিল্টার
            </button>
            @if (request()->hasAny(['q', 'status', 'type', 'category', 'author']))
                <a href="{{ route('admin.articles.index') }}"
                   class="rounded-lg border border-line px-3 py-2 text-sm text-body">রিসেট</a>
            @endif
        </div>
    </form>

    <div class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-sm">
                <thead class="border-b border-line bg-surface-2 text-start">
                    <tr class="text-xs uppercase tracking-wide text-muted">
                        <th class="px-4 py-2.5 text-start font-semibold">শিরোনাম</th>
                        <th class="px-4 py-2.5 text-start font-semibold">বিভাগ</th>
                        <th class="px-4 py-2.5 text-start font-semibold">লেখক</th>
                        <th class="px-4 py-2.5 text-start font-semibold">অবস্থা</th>
                        <th class="px-4 py-2.5 text-end font-semibold">ভিউ</th>
                        <th class="px-4 py-2.5 text-start font-semibold">সময়</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($articles as $article)
                        <tr class="hover:bg-surface-2/50">
                            <td class="max-w-md px-4 py-2.5">
                                <a href="{{ route('admin.articles.edit', $article) }}"
                                   class="line-clamp-2 font-medium text-ink hover:text-brand">
                                    {{ $article->title }}
                                </a>
                                <div class="mt-0.5 flex gap-1.5 text-2xs">
                                    @if ($article->is_lead)<span class="rounded bg-brand px-1 text-white">লিড</span>@endif
                                    @if ($article->is_breaking)<span class="rounded bg-red-600 px-1 text-white">ব্রেকিং</span>@endif
                                    @if ($article->is_premium)<span class="rounded bg-amber-400 px-1 text-amber-950">প্রিমিয়াম</span>@endif
                                    <span class="text-muted">{{ $article->type->label() }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center gap-1.5 text-xs text-body">
                                    <span class="h-2 w-2 rounded-full" style="background: {{ $article->category?->color }}"></span>
                                    {{ $article->category?->name }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-xs text-muted">{{ $article->author?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full px-2 py-0.5 text-2xs font-semibold {{ $article->status->color() }}">
                                    {{ $article->status->label() }}
                                </span>
                            </td>
                            <td class="lat px-4 py-2.5 text-end text-xs text-muted">@bncount($article->views)</td>
                            <td class="px-4 py-2.5 text-xs text-muted">
                                {{ App\Support\Bangla::ago($article->updated_at) }}
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-1">
                                    @can('publish', App\Models\Article::class)
                                        @if ($article->status !== App\Enums\ArticleStatus::Published)
                                            <form method="POST" action="{{ route('admin.articles.status', $article) }}">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="status" value="published">
                                                <button type="submit" title="প্রকাশ করুন"
                                                        class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-green-600">
                                                    <x-ui.icon name="check" class="h-4 w-4" />
                                                </button>
                                            </form>
                                        @endif
                                    @endcan

                                    @if ($article->type === App\Enums\ArticleType::Live)
                                        <a href="{{ route('admin.live.index', $article) }}" title="লাইভ আপডেট"
                                           class="rounded-md p-1.5 text-brand hover:bg-surface-2">
                                            <x-ui.icon name="clock" class="h-4 w-4" />
                                        </a>
                                    @endif

                                    @if ($article->isVisible())
                                        <a href="{{ $article->url }}" target="_blank" title="সাইটে দেখুন"
                                           class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-brand">
                                            <x-ui.icon name="eye" class="h-4 w-4" />
                                        </a>
                                    @endif

                                    @can('delete', $article)
                                        <form method="POST" action="{{ route('admin.articles.destroy', $article) }}"
                                              onsubmit="return confirm('এই খবরটি ট্র্যাশে পাঠাতে চান?')">
                                            @csrf @method('DELETE')
                                            <button type="submit" title="মুছুন"
                                                    class="rounded-md p-1.5 text-muted hover:bg-surface-2 hover:text-brand">
                                                <x-ui.icon name="close" class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-muted">কোনো খবর পাওয়া যায়নি।</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $articles->links() }}</div>
@endsection
