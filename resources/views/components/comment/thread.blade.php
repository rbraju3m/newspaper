@props(['article'])

@php
    $comments = $article->threadedComments()->paginate(20, pageName: 'comments');
@endphp

<div x-data="{ replyTo: null, editing: null }">

    {{-- Composer --}}
    @auth
        @if ($article->allow_comments)
            @if (! auth()->user()->hasVerifiedEmail())
                <x-ui.alert type="warning" title="ইমেইল যাচাই প্রয়োজন">
                    মন্তব্য করতে প্রথমে
                    <a href="{{ route('verification.notice') }}" class="font-semibold underline">ইমেইল যাচাই করুন</a>।
                </x-ui.alert>
            @else
                <form method="POST" action="{{ route('comments.store', $article) }}"
                      class="flex gap-3 rounded-xl border border-line bg-surface p-4">
                    @csrf
                    <img src="{{ auth()->user()->avatar_url }}" alt="" width="40" height="40"
                         class="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-line">

                    <div class="min-w-0 flex-1" x-data="{ body: '', max: 2000 }">
                        <label for="comment-body" class="sr-only">আপনার মন্তব্য</label>
                        <textarea id="comment-body" name="body" rows="3" required maxlength="2000"
                                  x-model="body"
                                  placeholder="আপনার মন্তব্য লিখুন…"
                                  class="w-full resize-y rounded-lg border border-line-strong bg-canvas
                                         px-3.5 py-2.5 text-base text-ink outline-none
                                         placeholder:text-muted focus:border-brand">{{ old('body') }}</textarea>

                        @error('body')
                            <p class="mt-1.5 text-xs font-medium text-brand">{{ $message }}</p>
                        @enderror

                        <div class="mt-2 flex items-center justify-between gap-3">
                            <p class="text-xs text-muted">
                                <a href="{{ route('page.show', 'comment-policy') }}"
                                   class="underline hover:text-brand">মন্তব্য নীতি</a> মেনে লিখুন।
                            </p>
                            <div class="flex items-center gap-3">
                                <span class="lat text-xs"
                                      :class="body.length > max * 0.9 ? 'text-brand' : 'text-muted'"
                                      x-text="`${body.length}/${max}`"></span>
                                <button type="submit" :disabled="body.trim().length < 10"
                                        class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white
                                               transition hover:bg-brand-700 disabled:cursor-not-allowed
                                               disabled:opacity-50">
                                    পোস্ট করুন
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            @endif
        @else
            <x-ui.alert type="info">এই খবরে মন্তব্যের সুযোগ বন্ধ রাখা হয়েছে।</x-ui.alert>
        @endif
    @else
        <div class="rounded-xl border border-line bg-surface p-6 text-center">
            <p class="text-sm text-body">মন্তব্য করতে লগইন করুন।</p>
            <div class="mt-3 flex justify-center gap-2">
                <a href="{{ route('login', ['redirect' => request()->path()]) }}"
                   class="rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                    লগইন
                </a>
                <a href="{{ route('register') }}"
                   class="rounded-lg border border-line-strong px-5 py-2 text-sm font-semibold text-ink
                          hover:border-brand hover:text-brand">
                    নিবন্ধন
                </a>
            </div>
        </div>
    @endauth

    {{-- Thread --}}
    @if ($comments->isNotEmpty())
        <ol class="mt-6 space-y-5">
            @foreach ($comments as $comment)
                <li>
                    <x-comment.item :comment="$comment" :article="$article" />

                    @if ($comment->replies->isNotEmpty())
                        {{-- One nesting level only — deeper threads are
                             unreadable on a phone, which is most of this traffic. --}}
                        <ol class="mt-4 space-y-4 border-s-2 border-line ps-4 sm:ms-6 sm:ps-5">
                            @foreach ($comment->replies as $reply)
                                <li><x-comment.item :comment="$reply" :article="$article" :is-reply="true" /></li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach
        </ol>

        <div class="mt-6">{{ $comments->links() }}</div>
    @else
        <p class="mt-6 text-center text-sm text-muted">
            এখনো কোনো মন্তব্য নেই। প্রথম মন্তব্যটি আপনিই করুন।
        </p>
    @endif
</div>
