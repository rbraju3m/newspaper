@extends('layouts.admin')
@section('title', 'মন্তব্য')
@section('heading', 'মন্তব্য মডারেশন')

@section('content')
    {{-- Status tabs double as the queue counts --}}
    <div class="no-scrollbar mb-4 flex gap-1.5 overflow-x-auto">
        @foreach ([
            'pending' => ['অপেক্ষমাণ', $counts['pending']],
            'approved' => ['অনুমোদিত', $counts['approved']],
            'rejected' => ['প্রত্যাখ্যাত', $counts['rejected']],
            'spam' => ['স্প্যাম', $counts['spam']],
            'all' => ['সব', null],
        ] as $key => [$label, $count])
            <a href="{{ route('admin.comments.index', ['status' => $key]) }}"
               @class([
                   'flex shrink-0 items-center gap-2 rounded-lg px-3.5 py-2 text-sm font-medium transition',
                   'bg-brand text-white' => $status === $key,
                   'border border-line bg-surface text-body hover:border-brand' => $status !== $key,
               ])>
                {{ $label }}
                @if ($count !== null)
                    <span class="lat rounded-full px-1.5 py-0.5 text-2xs font-bold
                                 {{ $status === $key ? 'bg-white/20' : 'bg-surface-2' }}">@bn($count)</span>
                @endif
            </a>
        @endforeach

        @if ($counts['reported'] > 0)
            <a href="{{ route('admin.comments.index', ['reported' => 1, 'status' => 'all']) }}"
               class="flex shrink-0 items-center gap-2 rounded-lg border border-brand bg-brand-50 px-3.5 py-2
                      text-sm font-medium text-brand dark:bg-transparent">
                রিপোর্ট করা
                <span class="lat rounded-full bg-brand px-1.5 py-0.5 text-2xs font-bold text-white">
                    @bn($counts['reported'])
                </span>
            </a>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.comments.bulk') }}"
          x-data="{ selected: [], get all() { return this.selected.length > 0 } }">
        @csrf

        {{-- Bulk action bar appears only when something is selected --}}
        <div x-show="all" x-cloak
             class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-brand bg-brand-50 p-3
                    dark:bg-transparent">
            <span class="text-sm font-medium text-ink">
                <span class="lat" x-text="selected.length"></span> টি নির্বাচিত
            </span>
            @foreach ([
                ['approve', 'অনুমোদন', 'bg-green-600'],
                ['reject', 'প্রত্যাখ্যান', 'bg-slate-600'],
                ['spam', 'স্প্যাম', 'bg-amber-600'],
                ['delete', 'মুছুন', 'bg-brand'],
            ] as [$action, $label, $colour])
                <button type="submit" name="action" value="{{ $action }}"
                        @if ($action === 'delete') onclick="return confirm('নির্বাচিত মন্তব্যগুলো মুছে ফেলতে চান?')" @endif
                        class="rounded-lg {{ $colour }} px-3.5 py-1.5 text-sm font-semibold text-white">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="space-y-3">
            @forelse ($comments as $comment)
                <article class="rounded-xl border border-line bg-surface p-4">
                    <div class="flex items-start gap-3">
                        <input type="checkbox" name="ids[]" value="{{ $comment->id }}" x-model="selected"
                               class="mt-1.5 rounded border-line-strong accent-[var(--color-brand)]">

                        <img src="{{ $comment->user->avatar_url }}" alt="" width="36" height="36"
                             class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-line">

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span class="text-sm font-semibold text-ink">{{ $comment->user->name }}</span>
                                <span class="text-xs text-muted">{{ $comment->user->email }}</span>
                                <span class="rounded-full px-2 py-0.5 text-2xs font-semibold
                                             {{ $comment->status->color() }}">
                                    {{ $comment->status->label() }}
                                </span>
                                @if ($comment->reports_count > 0)
                                    <span class="rounded bg-brand px-1.5 py-0.5 text-2xs font-bold text-white">
                                        @bn($comment->reports_count) রিপোর্ট
                                    </span>
                                @endif
                            </div>

                            <p class="mt-1.5 whitespace-pre-line text-sm text-body">{{ $comment->body }}</p>

                            <p class="mt-1.5 text-xs text-muted">
                                {{ App\Support\Bangla::ago($comment->created_at) }} ·
                                <a href="{{ $comment->article?->url }}" target="_blank"
                                   class="text-link hover:text-brand">{{ Str::limit($comment->article?->title, 50) }}</a>
                                @if ($comment->ip)<span class="lat"> · {{ $comment->ip }}</span>@endif
                            </p>
                        </div>

                        <div class="flex shrink-0 gap-1">
                            @foreach ([
                                ['approved', 'check', 'অনুমোদন', 'hover:text-green-600'],
                                ['rejected', 'close', 'প্রত্যাখ্যান', 'hover:text-slate-600'],
                                ['spam', 'newspaper', 'স্প্যাম', 'hover:text-amber-600'],
                            ] as [$to, $icon, $title, $hover])
                                @continue($comment->status->value === $to)
                                <button type="submit" title="{{ $title }}"
                                        form="moderate-{{ $comment->id }}"
                                        name="status" value="{{ $to }}"
                                        class="rounded-md p-1.5 text-muted hover:bg-surface-2 {{ $hover }}">
                                    <x-ui.icon name="{{ $icon }}" class="h-4 w-4" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                </article>
            @empty
                <x-ui.empty-state icon="comment" title="এই তালিকায় কোনো মন্তব্য নেই" />
            @endforelse
        </div>
    </form>

    {{-- Per-comment moderation forms live outside the bulk form; the action
         buttons above reach them through the HTML5 form="" attribute, because
         nesting a form inside another is invalid. --}}
    @foreach ($comments as $comment)
        <form id="moderate-{{ $comment->id }}" method="POST"
              action="{{ route('admin.comments.update', $comment) }}" class="hidden">
            @csrf @method('PATCH')
        </form>
    @endforeach

    <div class="mt-4">{{ $comments->links() }}</div>
@endsection
