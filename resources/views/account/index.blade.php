@extends('layouts.site')
@section('title', 'আমার প্রোফাইল — '.config('site.name_bn'))

@section('content')
<x-account.shell title="আমার প্রোফাইল" active="index">

    {{-- Stat tiles --}}
    <div class="grid grid-cols-3 gap-3">
        @foreach ([
            ['bookmark', 'সংরক্ষিত', $stats['bookmarks'], 'account.bookmarks'],
            ['clock', 'পড়া হয়েছে', $stats['history'], 'account.history'],
            ['comment', 'মন্তব্য', $stats['comments'], null],
        ] as [$icon, $label, $value, $route])
            <a @if($route) href="{{ route($route) }}" @endif
               class="rounded-xl border border-line bg-surface p-4 transition
                      {{ $route ? 'hover:border-brand' : '' }}">
                <x-ui.icon name="{{ $icon }}" class="h-4 w-4 text-muted" />
                <p class="mt-1.5 font-headline text-2xl font-bold text-ink">@bn($value)</p>
                <p class="text-xs text-muted">{{ $label }}</p>
            </a>
        @endforeach
    </div>

    {{-- Profile form --}}
    <section class="mt-6 rounded-xl border border-line bg-surface p-5 lg:p-6">
        <h2 class="font-headline text-lg font-bold text-ink">ব্যক্তিগত তথ্য</h2>

        <form method="POST" action="{{ route('account.update') }}" enctype="multipart/form-data"
              class="mt-4 space-y-4" x-data="{ preview: null }">
            @csrf
            @method('PATCH')

            <div class="flex items-center gap-4">
                <img :src="preview ?? '{{ $user->avatar_url }}'" alt="" width="72" height="72"
                     class="h-18 w-18 rounded-full object-cover ring-1 ring-line">
                <div>
                    <label for="f-avatar"
                           class="cursor-pointer rounded-lg border border-line-strong px-3.5 py-2
                                  text-sm font-semibold text-ink transition hover:border-brand hover:text-brand">
                        ছবি পরিবর্তন করুন
                    </label>
                    <input id="f-avatar" type="file" name="avatar" accept="image/*" class="sr-only"
                           @change="preview = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
                    <p class="mt-1.5 text-xs text-muted">JPG, PNG বা WebP — সর্বোচ্চ ২ মেগাবাইট।</p>
                    @error('avatar')<p class="mt-1 text-xs font-medium text-brand">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="পূর্ণ নাম" :value="$user->name" :required="true" />
                <x-form.input name="phone" label="মোবাইল নম্বর" type="tel" :value="$user->phone" />
            </div>

            <x-form.input name="email" label="ইমেইল ঠিকানা" type="email" :value="$user->email" :required="true"
                          hint="ইমেইল পরিবর্তন করলে নতুন ঠিকানাটি আবার যাচাই করতে হবে।" />

            <div>
                <label for="f-bio" class="mb-1.5 block text-sm font-semibold text-ink">
                    নিজের সম্পর্কে <span class="font-normal text-muted">(ঐচ্ছিক)</span>
                </label>
                <textarea id="f-bio" name="bio" rows="3" maxlength="500"
                          class="w-full rounded-lg border border-line-strong bg-canvas px-3.5 py-2.5
                                 text-base text-ink outline-none focus:border-brand
                                 focus:ring-2 focus:ring-brand/20">{{ old('bio', $user->bio) }}</textarea>
                @error('bio')<p class="mt-1.5 text-xs font-medium text-brand">{{ $message }}</p>@enderror
            </div>

            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                সংরক্ষণ করুন
            </button>
        </form>
    </section>

    {{-- Password --}}
    <section class="mt-6 rounded-xl border border-line bg-surface p-5 lg:p-6">
        <h2 class="font-headline text-lg font-bold text-ink">পাসওয়ার্ড পরিবর্তন</h2>

        <form method="POST" action="{{ route('account.password.update') }}" class="mt-4 space-y-4">
            @csrf
            @method('PUT')

            @if ($errors->password->any())
                <x-ui.alert type="error">{{ $errors->password->first() }}</x-ui.alert>
            @endif

            <x-form.password name="current_password" label="বর্তমান পাসওয়ার্ড" autocomplete="current-password" />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.password name="password" label="নতুন পাসওয়ার্ড" autocomplete="new-password" />
                <x-form.password name="password_confirmation" label="নিশ্চিত করুন" autocomplete="new-password" />
            </div>

            <button type="submit"
                    class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                পাসওয়ার্ড পরিবর্তন করুন
            </button>
        </form>
    </section>

    {{-- Continue reading --}}
    @if ($recent->isNotEmpty())
        <section class="mt-6">
            <x-ui.section-header title="আবার পড়ুন" :href="route('account.history')" />
            <div class="grid gap-x-6 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($recent as $article)
                    <x-article.card :article="$article" variant="standard" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- Danger zone --}}
    <section class="mt-8 rounded-xl border border-brand/30 bg-brand-50/50 p-5 dark:bg-transparent lg:p-6"
             x-data="{ open: false }">
        <h2 class="font-headline text-lg font-bold text-ink">অ্যাকাউন্ট মুছে ফেলুন</h2>
        <p class="mt-1 text-sm text-body">
            অ্যাকাউন্ট মুছে ফেললে আপনার সংরক্ষিত খবর ও পড়ার ইতিহাস আর ফিরে পাওয়া যাবে না।
        </p>

        <button type="button" @click="open = !open"
                class="mt-3 rounded-lg border border-brand px-4 py-2 text-sm font-semibold
                       text-brand transition hover:bg-brand hover:text-white">
            অ্যাকাউন্ট মুছে ফেলুন
        </button>

        <form x-show="open" x-cloak x-collapse method="POST" action="{{ route('account.destroy') }}"
              class="mt-4 space-y-3">
            @csrf
            @method('DELETE')

            @if ($errors->delete->any())
                <x-ui.alert type="error">{{ $errors->delete->first() }}</x-ui.alert>
            @endif

            <x-form.password name="password" label="নিশ্চিত করতে পাসওয়ার্ড দিন" autocomplete="current-password" />

            <div class="flex gap-2">
                <button type="submit"
                        class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                    স্থায়ীভাবে মুছে ফেলুন
                </button>
                <button type="button" @click="open = false"
                        class="rounded-lg border border-line-strong px-5 py-2.5 text-sm font-semibold text-ink">
                    বাতিল
                </button>
            </div>
        </form>
    </section>
</x-account.shell>
@endsection
