@props(['comment', 'article', 'isReply' => false])

<article id="comment-{{ $comment->id }}"
         class="scroll-mt-24 rounded-xl border border-line bg-surface p-4"
         x-data="{
            editing: false,
            liked: false,
            likes: {{ $comment->likes_count }},
            reported: false,
            busy: false,
            async like() {
                if (this.busy) return;
                @guest window.location = '{{ route('login') }}'; return; @endguest
                this.busy = true;
                try {
                    const res = await fetch('{{ route('comments.like', $comment) }}', {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    });
                    const d = await res.json();
                    this.liked = d.liked; this.likes = d.count;
                } finally { this.busy = false; }
            },
            async report() {
                if (this.reported) return;
                await fetch('{{ route('comments.report', $comment) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                });
                this.reported = true;
            },
         }">

    <div class="flex items-start gap-3">
        <img src="{{ $comment->user->avatar_url }}" alt=""
             width="{{ $isReply ? 32 : 40 }}" height="{{ $isReply ? 32 : 40 }}" loading="lazy"
             class="{{ $isReply ? 'h-8 w-8' : 'h-10 w-10' }} shrink-0 rounded-full object-cover ring-1 ring-line">

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span class="text-sm font-semibold text-ink">{{ $comment->user->name }}</span>

                @if ($comment->user->role->isStaff())
                    <span class="rounded bg-brand px-1.5 py-0.5 text-2xs font-bold text-white">
                        {{ $comment->user->role->label() }}
                    </span>
                @endif

                <time class="text-xs text-muted" datetime="{{ $comment->created_at->toIso8601String() }}">
                    {{ App\Support\Bangla::ago($comment->created_at) }}
                </time>

                @if ($comment->updated_at->gt($comment->created_at->addMinute()))
                    <span class="text-2xs text-muted">(সম্পাদিত)</span>
                @endif
            </div>

            {{-- Body / inline edit form --}}
            <p x-show="!editing" class="mt-1.5 whitespace-pre-line text-base leading-relaxed text-body">
                {{ $comment->body }}
            </p>

            @can('update', $comment)
                <form x-show="editing" x-cloak method="POST"
                      action="{{ route('comments.update', $comment) }}" class="mt-2">
                    @csrf
                    @method('PATCH')
                    <label for="edit-{{ $comment->id }}" class="sr-only">মন্তব্য সম্পাদনা</label>
                    <textarea id="edit-{{ $comment->id }}" name="body" rows="3" required
                              class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2
                                     text-base text-ink outline-none focus:border-brand">{{ $comment->body }}</textarea>
                    <div class="mt-2 flex gap-2">
                        <button type="submit"
                                class="rounded-lg bg-brand px-4 py-1.5 text-sm font-semibold text-white">
                            সংরক্ষণ
                        </button>
                        <button type="button" @click="editing = false"
                                class="rounded-lg border border-line px-4 py-1.5 text-sm font-semibold text-body">
                            বাতিল
                        </button>
                    </div>
                </form>
            @endcan

            {{-- Actions --}}
            <div x-show="!editing" class="mt-2 flex flex-wrap items-center gap-3 text-xs">
                <button type="button" @click="like()"
                        class="flex items-center gap-1.5 font-medium text-muted transition hover:text-brand"
                        :class="liked && 'text-brand'" :aria-pressed="liked">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" :fill="liked ? 'currentColor' : 'none'" aria-hidden="true">
                        <path d="M7 22V11l5-9a2.5 2.5 0 012.4 3.2L13.5 9H19a2 2 0 012 2.3l-1.3 8A2 2 0 0117.7 21H7z"/>
                    </svg>
                    <span class="lat" x-text="likes || ''"></span>
                    <span>ভালো লাগল</span>
                </button>

                @if (! $isReply)
                    @auth
                        <button type="button" @click="
                            const box = document.getElementById('comment-body');
                            if (!box) return;
                            box.value = {{ Js::from('@'.$comment->user->name.' ') }};
                            box.focus();
                            box.dispatchEvent(new Event('input'));
                            box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        " class="font-medium text-muted transition hover:text-brand">উত্তর দিন</button>
                    @else
                        <a href="{{ route('login', ['redirect' => request()->path()]) }}"
                           class="font-medium text-muted transition hover:text-brand">উত্তর দিন</a>
                    @endauth
                @endif

                @can('update', $comment)
                    <button type="button" @click="editing = true"
                            class="font-medium text-muted transition hover:text-brand">সম্পাদনা</button>
                @endcan

                @can('delete', $comment)
                    <form method="POST" action="{{ route('comments.destroy', $comment) }}"
                          onsubmit="return confirm('এই মন্তব্যটি মুছে ফেলতে চান?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="font-medium text-muted transition hover:text-brand">
                            মুছুন
                        </button>
                    </form>
                @endcan

                <button type="button" @click="report()" :disabled="reported"
                        class="ms-auto font-medium text-muted transition hover:text-brand disabled:opacity-50">
                    <span x-show="!reported">রিপোর্ট</span>
                    <span x-show="reported" x-cloak>রিপোর্ট করা হয়েছে</span>
                </button>
            </div>
        </div>
    </div>
</article>
