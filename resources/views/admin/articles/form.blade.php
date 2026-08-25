@extends('layouts.admin')
@section('title', $article->exists ? 'খবর সম্পাদনা' : 'নতুন খবর')
@section('heading', $article->exists ? 'খবর সম্পাদনা' : 'নতুন খবর')

@section('content')
<form method="POST"
      action="{{ $article->exists ? route('admin.articles.update', $article) : route('admin.articles.store') }}"
      x-data="articleEditor({{ Js::from([
          'title' => old('title', $article->title),
          'image' => old('image', $article->image),
          'imageId' => old('image_id', $article->image_id),
          'articleSlug' => $article->slug,
          'imageUrl' => $article->image_url,
          'tags' => old('tags', $article->exists ? $article->tags->pluck('name')->all() : []),
          'mediaEndpoint' => route('admin.media.index'),
          'uploadEndpoint' => route('admin.media.store'),
      ]) }})">
    @csrf
    @if ($article->exists) @method('PUT') @endif

    <div class="grid gap-5 lg:grid-cols-12">

        {{-- ── Main column ────────────────────────────────────────────── --}}
        <div class="space-y-5 lg:col-span-8">

            <section class="rounded-xl border border-line bg-surface p-5">
                <label for="f-title" class="mb-1.5 block text-sm font-semibold text-ink">শিরোনাম</label>
                <textarea id="f-title" name="title" rows="2" required maxlength="500"
                          x-model="title"
                          class="w-full resize-none rounded-lg border border-line-strong bg-canvas px-3.5 py-2.5
                                 font-headline text-xl font-bold text-ink outline-none focus:border-brand"
                          placeholder="খবরের শিরোনাম লিখুন">{{ old('title', $article->title) }}</textarea>
                @error('title')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror

                {{-- Live slug preview: editors should see the URL they are
                     creating, because it is permanent once published. --}}
                <div class="mt-2 flex items-center gap-2 text-xs">
                    <span class="shrink-0 text-muted">ঠিকানা:</span>
                    <input type="text" name="slug" value="{{ old('slug', $article->slug) }}"
                           placeholder="শিরোনাম থেকে স্বয়ংক্রিয়ভাবে তৈরি হবে"
                           class="min-w-0 flex-1 rounded border border-line bg-canvas px-2 py-1 text-xs text-body">
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="f-kicker" class="mb-1.5 block text-sm font-semibold text-ink">
                            কিকার <span class="font-normal text-muted">(ঐচ্ছিক)</span>
                        </label>
                        <input id="f-kicker" name="kicker" value="{{ old('kicker', $article->kicker) }}" maxlength="200"
                               class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    </div>
                    <div>
                        <label for="f-dateline" class="mb-1.5 block text-sm font-semibold text-ink">ডেটলাইন</label>
                        <input id="f-dateline" name="dateline" value="{{ old('dateline', $article->dateline) }}"
                               placeholder="নিজস্ব প্রতিবেদক" maxlength="120"
                               class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    </div>
                </div>

                <div class="mt-3">
                    <label for="f-subtitle" class="mb-1.5 block text-sm font-semibold text-ink">
                        উপশিরোনাম <span class="font-normal text-muted">(ঐচ্ছিক)</span>
                    </label>
                    <input id="f-subtitle" name="subtitle" value="{{ old('subtitle', $article->subtitle) }}" maxlength="500"
                           class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                </div>

                <div class="mt-3">
                    <label for="f-excerpt" class="mb-1.5 block text-sm font-semibold text-ink">সারসংক্ষেপ</label>
                    <textarea id="f-excerpt" name="excerpt" rows="2" maxlength="1000"
                              class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink"
                              placeholder="কার্ড ও শেয়ারে যে সংক্ষিপ্ত বিবরণ দেখাবে">{{ old('excerpt', $article->excerpt) }}</textarea>
                    @error('excerpt')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                </div>
            </section>

            {{-- Body editor --}}
            <section class="rounded-xl border border-line bg-surface p-5">
                <div class="mb-2 flex items-center justify-between">
                    <label for="f-body" class="text-sm font-semibold text-ink">বিস্তারিত</label>
                    <div class="flex items-center gap-1" x-data="richText('f-body')">
                        @foreach ([
                            ['h2', 'H2', 'শিরোনাম'], ['h3', 'H3', 'উপশিরোনাম'],
                            ['b', 'B', 'বোল্ড'], ['i', 'I', 'ইটালিক'],
                            ['ul', '•', 'তালিকা'], ['quote', '❝', 'উদ্ধৃতি'],
                            ['link', '🔗', 'লিংক'], ['img', '🖼', 'ছবি'],
                        ] as [$cmd, $glyph, $title])
                            <button type="button" @click="wrap('{{ $cmd }}')" title="{{ $title }}"
                                    class="rounded border border-line px-2 py-1 text-xs font-bold text-body
                                           hover:border-brand hover:text-brand">{{ $glyph }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Deliberately a plain textarea over an HTML editor: the body
                     is stored as HTML and rendered with {!! !!}, so a WYSIWYG
                     would need server-side sanitising before it could be
                     trusted with untrusted input. Staff-only for now. --}}
                <textarea id="f-body" name="body" rows="20"
                          class="w-full rounded-lg border border-line-strong bg-canvas px-3.5 py-3 font-mono
                                 text-sm leading-relaxed text-ink outline-none focus:border-brand"
                          placeholder="<p>এখানে খবরের বিবরণ লিখুন…</p>">{{ old('body', $article->body) }}</textarea>
                @error('body')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                <p class="mt-1.5 text-xs text-muted">
                    HTML ব্যবহার করা যাবে: &lt;p&gt;, &lt;h2&gt;, &lt;ul&gt;, &lt;blockquote&gt;, &lt;figure&gt;
                </p>
            </section>

            {{-- SEO --}}
            <section class="rounded-xl border border-line bg-surface p-5" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
                    <h2 class="font-headline text-base font-bold text-ink">SEO ও শেয়ার প্রিভিউ</h2>
                    <x-ui.icon name="chevron-down" class="h-4 w-4 transition" ::class="open && 'rotate-180'" />
                </button>

                <div x-show="open" x-cloak x-collapse class="mt-4 space-y-3">
                    {{-- Search-result preview so editors see what Google shows --}}
                    <div class="rounded-lg border border-line bg-canvas p-3">
                        <p class="truncate text-xs text-green-700 dark:text-green-500">
                            {{ config('app.url') }}/{{ $article->category?->path ?? 'বিভাগ' }}/…
                        </p>
                        <p class="mt-0.5 truncate text-base text-blue-700 dark:text-blue-400"
                           x-text="metaTitle || title || 'শিরোনাম'"></p>
                        <p class="mt-0.5 line-clamp-2 text-xs text-muted"
                           x-text="metaDescription || 'সারসংক্ষেপ এখানে দেখাবে…'"></p>
                    </div>

                    <div>
                        <label for="f-mt" class="mb-1.5 block text-sm font-semibold text-ink">মেটা শিরোনাম</label>
                        <input id="f-mt" name="meta_title" x-model="metaTitle" maxlength="255"
                               value="{{ old('meta_title', $article->meta_title) }}"
                               class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                        <p class="mt-1 text-xs text-muted">
                            <span class="lat" x-text="(metaTitle || title || '').length"></span>/৬০ অক্ষর আদর্শ
                        </p>
                    </div>

                    <div>
                        <label for="f-md" class="mb-1.5 block text-sm font-semibold text-ink">মেটা বিবরণ</label>
                        <textarea id="f-md" name="meta_description" x-model="metaDescription" rows="2" maxlength="500"
                                  class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">{{ old('meta_description', $article->meta_description) }}</textarea>
                    </div>
                </div>
            </section>
        </div>

        {{-- ── Sidebar ────────────────────────────────────────────────── --}}
        <div class="space-y-5 lg:col-span-4">

            {{-- Publish box --}}
            <section class="rounded-xl border border-line bg-surface p-4">
                <h2 class="font-headline text-base font-bold text-ink">প্রকাশনা</h2>

                <div class="mt-3 space-y-3">
                    <div>
                        <label for="f-status" class="mb-1.5 block text-sm font-semibold text-ink">অবস্থা</label>
                        <select id="f-status" name="status" x-model="status"
                                class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                            @foreach ($statuses as $s)
                                @continue(! auth()->user()->role->canPublish() && in_array($s->value, ['published', 'scheduled']))
                                <option value="{{ $s->value }}"
                                        @selected(old('status', $article->status?->value ?? 'draft') === $s->value)>
                                    {{ $s->label() }}
                                </option>
                            @endforeach
                        </select>
                        @unless (auth()->user()->role->canPublish())
                            <p class="mt-1 text-xs text-muted">প্রকাশের অনুমতি সম্পাদকের কাছে। "পর্যালোচনায়" পাঠান।</p>
                        @endunless
                    </div>

                    <div x-show="status === 'scheduled' || status === 'published'" x-cloak>
                        <label for="f-pub" class="mb-1.5 block text-sm font-semibold text-ink">প্রকাশের সময়</label>
                        <input id="f-pub" type="datetime-local" name="published_at"
                               value="{{ old('published_at', $article->published_at?->format('Y-m-d\TH:i')) }}"
                               class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                        @error('published_at')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="f-type" class="mb-1.5 block text-sm font-semibold text-ink">ধরন</label>
                        <select id="f-type" name="type" x-model="type"
                                class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                            @foreach ($types as $t)
                                <option value="{{ $t->value }}"
                                        @selected(old('type', $article->type?->value ?? 'news') === $t->value)>
                                    {{ $t->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="type === 'video'" x-cloak>
                        <label for="f-video" class="mb-1.5 block text-sm font-semibold text-ink">ভিডিও লিংক</label>
                        <input id="f-video" name="video_url" value="{{ old('video_url', $article->video_url) }}"
                               placeholder="https://youtube.com/watch?v=…"
                               class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                        @error('video_url')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="mt-4 flex gap-2 border-t border-line pt-4">
                    <button type="submit"
                            class="flex-1 rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                        সংরক্ষণ করুন
                    </button>
                    @if ($article->exists && $article->isVisible())
                        <a href="{{ $article->url }}" target="_blank"
                           class="rounded-lg border border-line px-3 py-2.5 text-sm font-semibold text-ink">
                            <x-ui.icon name="eye" class="h-4 w-4" />
                        </a>
                    @endif
                </div>
            </section>

            {{-- Placement — editorial only --}}
            @can('feature', App\Models\Article::class)
                <section class="rounded-xl border border-line bg-surface p-4">
                    <h2 class="font-headline text-base font-bold text-ink">প্রচ্ছদে স্থান</h2>
                    <div class="mt-3 space-y-2">
                        @foreach ([
                            ['is_lead', 'প্রধান খবর (লিড)'],
                            ['is_featured', 'ফিচার্ড'],
                            ['is_pinned', 'উপরে পিন করুন'],
                            ['is_breaking', 'ব্রেকিং নিউজ'],
                            ['is_premium', 'প্রিমিয়াম'],
                            ['allow_comments', 'মন্তব্য চালু'],
                        ] as [$flag, $label])
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-body">
                                <input type="checkbox" name="{{ $flag }}" value="1"
                                       @checked(old($flag, $article->$flag ?? $flag === 'allow_comments'))
                                       @if ($flag === 'is_breaking') x-model="breaking" @endif
                                       class="rounded border-line-strong accent-[var(--color-brand)]">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div x-show="breaking" x-cloak class="mt-3">
                        <label for="f-bu" class="mb-1.5 block text-xs font-semibold text-ink">ব্রেকিং শেষ হবে</label>
                        <input id="f-bu" type="datetime-local" name="breaking_until"
                               value="{{ old('breaking_until', $article->breaking_until?->format('Y-m-d\TH:i')) }}"
                               class="lat w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-xs text-ink">
                        @error('breaking_until')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                    </div>
                </section>
            @endcan

            {{-- Category & author --}}
            <section class="rounded-xl border border-line bg-surface p-4">
                <h2 class="font-headline text-base font-bold text-ink">শ্রেণিবিন্যাস</h2>

                <div class="mt-3 space-y-3">
                    <div>
                        <label for="f-cat" class="mb-1.5 block text-sm font-semibold text-ink">বিভাগ</label>
                        <select id="f-cat" name="category_id" required
                                class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                            <option value="">— বেছে নিন —</option>
                            @foreach ($categories as $c)
                                <option value="{{ $c->id }}" @selected(old('category_id', $article->category_id) == $c->id)>
                                    {{ $c->parent_id ? '— ' : '' }}{{ $c->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_id')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                    </div>

                    @can('publish', App\Models\Article::class)
                        <div>
                            <label for="f-author" class="mb-1.5 block text-sm font-semibold text-ink">লেখক</label>
                            <select id="f-author" name="author_id"
                                    class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                                @foreach ($authors as $a)
                                    <option value="{{ $a->id }}"
                                            @selected(old('author_id', $article->author_id ?? auth()->id()) == $a->id)>
                                        {{ $a->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endcan

                    {{-- Tag token input --}}
                    <div>
                        <label for="f-tag-input" class="mb-1.5 block text-sm font-semibold text-ink">ট্যাগ</label>
                        <div class="rounded-lg border border-line-strong bg-canvas p-2">
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="(tag, i) in tags" :key="i">
                                    <span class="flex items-center gap-1 rounded bg-surface-2 px-2 py-1 text-xs text-body">
                                        <span x-text="tag"></span>
                                        <button type="button" @click="removeTag(i)" class="text-muted hover:text-brand">×</button>
                                        <input type="hidden" name="tags[]" :value="tag">
                                    </span>
                                </template>
                                <input id="f-tag-input" type="text" x-model="tagDraft"
                                       @keydown.enter.prevent="addTag()" @keydown.,.prevent="addTag()"
                                       @blur="addTag()"
                                       placeholder="ট্যাগ লিখে এন্টার চাপুন"
                                       class="min-w-32 flex-1 bg-transparent px-1 py-1 text-sm text-ink outline-none">
                            </div>
                        </div>
                    </div>

                    <div>
                        <span class="mb-1.5 block text-sm font-semibold text-ink">বিশেষ আয়োজন</span>
                        <div class="max-h-32 space-y-1 overflow-y-auto rounded-lg border border-line p-2">
                            @forelse ($topics as $t)
                                <label class="flex cursor-pointer items-center gap-2 text-sm text-body">
                                    <input type="checkbox" name="topics[]" value="{{ $t->id }}"
                                           @checked(in_array($t->id, old('topics', $article->exists ? $article->topics->pluck('id')->all() : [])))
                                           class="rounded border-line-strong accent-[var(--color-brand)]">
                                    {{ $t->name }}
                                </label>
                            @empty
                                <p class="text-xs text-muted">কোনো বিষয় তৈরি করা হয়নি।</p>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            {{-- Lead image --}}
            <section class="rounded-xl border border-line bg-surface p-4">
                <h2 class="font-headline text-base font-bold text-ink">প্রধান ছবি</h2>

                <input type="hidden" name="image" :value="image">
                <input type="hidden" name="image_id" :value="imageId">

                <div class="mt-3">
                    <template x-if="imageUrl">
                        <div class="relative">
                            <img :src="imageUrl" alt="" class="w-full rounded-lg border border-line object-cover">
                            <button type="button" @click="clearImage()"
                                    class="absolute end-2 top-2 rounded-full bg-black/60 p-1.5 text-white hover:bg-brand">
                                <x-ui.icon name="close" class="h-4 w-4" />
                            </button>
                        </div>
                    </template>

                    <template x-if="!imageUrl">
                        <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg
                                      border-2 border-dashed border-line py-8 text-center hover:border-brand"
                               @dragover.prevent @drop.prevent="upload($event.dataTransfer.files[0])">
                            <x-ui.icon name="camera" class="h-7 w-7 text-muted" />
                            <span class="text-sm text-body">ছবি টেনে আনুন বা ক্লিক করুন</span>
                            <span class="text-xs text-muted">JPG, PNG, WebP — সর্বোচ্চ ৮MB</span>
                            <input type="file" accept="image/*" class="sr-only"
                                   @change="upload($event.target.files[0])">
                        </label>
                    </template>

                    <p x-show="uploading" x-cloak class="mt-2 text-xs text-muted">আপলোড হচ্ছে…</p>
                    <p x-show="uploadError" x-cloak x-text="uploadError" class="mt-2 text-xs text-brand"></p>
                </div>

                <div class="mt-3 space-y-2">
                    <input name="image_caption" value="{{ old('image_caption', $article->image_caption) }}"
                           placeholder="ছবির ক্যাপশন" maxlength="500"
                           class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    <input name="image_credit" value="{{ old('image_credit', $article->image_credit) }}"
                           placeholder="ছবি: সংগৃহীত" maxlength="255"
                           class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                </div>
            </section>

            <input type="hidden" name="locale" value="{{ old('locale', $article->locale ?? 'bn') }}">
        </div>
    </div>
</form>
@endsection
