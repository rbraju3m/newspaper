@extends('layouts.site')
@section('title', 'পছন্দসমূহ — '.config('site.name_bn'))

@section('content')
<x-account.shell title="পছন্দসমূহ" active="preferences">
    @php
        $followed = auth()->user()->preferences['followed_categories'] ?? [];
        $breaking = auth()->user()->preferences['breaking_alerts'] ?? false;
    @endphp

    <form method="POST" action="{{ route('account.preferences.update') }}" class="space-y-6">
        @csrf
        @method('PATCH')

        <section class="rounded-xl border border-line bg-surface p-5 lg:p-6">
            <h2 class="font-headline text-lg font-bold text-ink">আগ্রহের বিষয়</h2>
            <p class="mt-1 text-sm text-muted">
                যেসব বিভাগ অনুসরণ করবেন, সেগুলোর খবর আপনার জন্য অগ্রাধিকার পাবে।
            </p>

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($categories as $category)
                    {{-- Checkbox styled as a chip; the peer selector keeps it
                         keyboard-accessible without any JS. --}}
                    <label class="cursor-pointer">
                        <input type="checkbox" name="followed_categories[]" value="{{ $category->id }}"
                               @checked(in_array($category->id, $followed)) class="peer sr-only">
                        <span class="block rounded-full border border-line px-4 py-2 text-sm font-medium
                                     text-body transition peer-checked:border-transparent
                                     peer-checked:bg-brand peer-checked:text-white
                                     peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40
                                     hover:border-brand">
                            {{ $category->name }}
                        </span>
                    </label>
                @endforeach
            </div>
        </section>

        <section class="rounded-xl border border-line bg-surface p-5 lg:p-6">
            <h2 class="font-headline text-lg font-bold text-ink">ইমেইল ও নোটিফিকেশন</h2>

            <div class="mt-4 space-y-4">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="newsletter" value="1"
                           @checked($subscriber && ! $subscriber->unsubscribed_at)
                           class="mt-1 rounded border-line-strong accent-[var(--color-brand)]">
                    <span>
                        <span class="block text-sm font-semibold text-ink">নিউজলেটার</span>
                        <span class="block text-xs text-muted">বাছাই করা খবর ইমেইলে পেতে চাই</span>
                    </span>
                </label>

                <div class="ps-7">
                    <label for="f-freq" class="mb-1.5 block text-sm font-medium text-ink">কত ঘন ঘন?</label>
                    <select id="f-freq" name="newsletter_frequency"
                            class="rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                        <option value="daily" @selected(($subscriber->frequency ?? 'daily') === 'daily')>প্রতিদিন</option>
                        <option value="weekly" @selected(($subscriber->frequency ?? '') === 'weekly')>সপ্তাহে একবার</option>
                    </select>
                </div>

                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="breaking_alerts" value="1" @checked($breaking)
                           class="mt-1 rounded border-line-strong accent-[var(--color-brand)]">
                    <span>
                        <span class="block text-sm font-semibold text-ink">ব্রেকিং নিউজ অ্যালার্ট</span>
                        <span class="block text-xs text-muted">গুরুত্বপূর্ণ খবর সঙ্গে সঙ্গে জানতে চাই</span>
                    </span>
                </label>

                {{-- Two controls, because they are two different things and
                     pretending otherwise makes one of them lie. The checkbox
                     above is the account's standing answer and follows the
                     reader everywhere; this one is the permission *this
                     browser* has granted, which no server can turn on. Switch
                     the account off and every browser goes quiet with it —
                     PreferenceController stands the subscriptions down. --}}
                <div class="rounded-lg border border-line bg-surface-2 p-4">
                    <p class="text-sm font-semibold text-ink">এই ব্রাউজারে পুশ নোটিফিকেশন</p>
                    <p class="mt-0.5 text-xs text-muted">
                        প্রতিটি ডিভাইসে আলাদাভাবে অনুমতি দিতে হয়। ফোনে চালু করলে ল্যাপটপে
                        আলাদা করে চালু করতে হবে।
                    </p>

                    <x-ui.push-toggle class="mt-3" />

                    <p x-data x-cloak x-show="! $store.push.supported" class="mt-3 text-xs text-muted">
                        এই ব্রাউজার পুশ নোটিফিকেশন সমর্থন করে না।
                    </p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-line bg-surface p-5 lg:p-6">
            <h2 class="font-headline text-lg font-bold text-ink">পড়ার অভিজ্ঞতা</h2>
            <p class="mt-1 text-sm text-muted">এই সেটিংস আপনার ব্রাউজারে সংরক্ষিত থাকে।</p>

            <div class="mt-4 space-y-4">
                <div>
                    <span class="mb-1.5 block text-sm font-medium text-ink">থিম</span>
                    <div class="flex gap-2">
                        @foreach ([['light','sun','লাইট'], ['dark','moon','ডার্ক'], ['system','monitor','সিস্টেম']] as [$mode, $icon, $label])
                            <button type="button" @click="$store.theme.set('{{ $mode }}')"
                                    class="flex items-center gap-2 rounded-lg border px-3.5 py-2 text-sm
                                           font-medium transition"
                                    :class="$store.theme.mode === '{{ $mode }}'
                                        ? 'border-brand bg-brand text-white'
                                        : 'border-line text-body hover:border-brand'">
                                <x-ui.icon name="{{ $icon }}" class="h-4 w-4" /> {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <span class="mb-1.5 block text-sm font-medium text-ink">লেখার আকার</span>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="$store.reader.smaller()"
                                :disabled="!$store.reader.canShrink"
                                class="rounded-lg border border-line px-3 py-2 text-sm font-bold
                                       text-body hover:border-brand disabled:opacity-40"
                                aria-label="অ− ফন্ট ছোট করুন">অ−</button>
                        <span class="lat min-w-10 text-center text-sm text-muted"
                              x-text="$store.reader.fontSize.toUpperCase()"></span>
                        <button type="button" @click="$store.reader.bigger()"
                                :disabled="!$store.reader.canGrow"
                                class="rounded-lg border border-line px-3 py-2 text-base font-bold
                                       text-body hover:border-brand disabled:opacity-40"
                                aria-label="অ+ ফন্ট বড় করুন">অ+</button>
                        <button type="button" @click="$store.reader.reset()"
                                class="ms-2 text-sm text-muted underline hover:text-brand">রিসেট</button>
                    </div>
                </div>
            </div>
        </section>

        <button type="submit"
                class="rounded-lg bg-brand px-6 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
            সংরক্ষণ করুন
        </button>
    </form>
</x-account.shell>
@endsection
