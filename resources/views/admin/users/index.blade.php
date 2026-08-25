@extends('layouts.admin')
@section('title', 'ব্যবহারকারী')
@section('heading', 'ব্যবহারকারী')

@section('content')
<div class="grid gap-5 lg:grid-cols-12">
    <section class="lg:col-span-8">
        <form method="GET" class="mb-3 flex gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="নাম বা ইমেইল"
                   class="min-w-0 flex-1 rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm">
            <select name="role" class="rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm">
                <option value="">সব ভূমিকা</option>
                @foreach ($roles as $r)
                    <option value="{{ $r->value }}" @selected(request('role') === $r->value)>{{ $r->label() }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">ফিল্টার</button>
        </form>

        <div class="space-y-2">
            @foreach ($users as $user)
                <div x-data="{ edit: false }" class="rounded-xl border border-line bg-surface p-3">
                    <div class="flex items-center gap-3">
                        <img src="{{ $user->avatar_url }}" alt="" width="36" height="36"
                             class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-ink">{{ $user->name }}</p>
                            <p class="truncate text-xs text-muted">{{ $user->email }}</p>
                        </div>
                        <span class="shrink-0 rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-semibold text-body">
                            {{ $user->role->label() }}
                        </span>
                        @if ($user->status !== 'active')
                            <span class="rounded bg-brand px-1.5 py-0.5 text-2xs font-bold text-white">স্থগিত</span>
                        @endif
                        <span class="lat shrink-0 text-xs text-muted">@bn($user->articles_count)</span>
                        <button type="button" @click="edit = !edit" class="rounded-md p-1.5 text-muted hover:text-brand">
                            <x-ui.icon name="settings" class="h-4 w-4" />
                        </button>
                    </div>

                    <div x-show="edit" x-cloak x-collapse class="mt-3 space-y-3 border-t border-line pt-3">
                        <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-2">
                            @csrf @method('PUT')
                            <div class="grid gap-2 sm:grid-cols-2">
                                <input name="name" value="{{ $user->name }}" required placeholder="নাম"
                                       class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                <input name="email" type="email" value="{{ $user->email }}" required placeholder="ইমেইল"
                                       class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                <input name="phone" value="{{ $user->phone }}" placeholder="মোবাইল"
                                       class="lat rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                <input name="designation" value="{{ $user->designation }}" placeholder="পদবি"
                                       class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                <select name="role" class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm"
                                        @disabled($user->id === auth()->id())>
                                    @foreach ($roles as $r)
                                        <option value="{{ $r->value }}" @selected($user->role === $r)>{{ $r->label() }}</option>
                                    @endforeach
                                </select>
                                <select name="status" class="rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                                    <option value="active" @selected($user->status === 'active')>সক্রিয়</option>
                                    <option value="suspended" @selected($user->status === 'suspended')>স্থগিত</option>
                                </select>
                            </div>
                            <textarea name="bio" rows="2" placeholder="পরিচিতি"
                                      class="w-full rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">{{ $user->bio }}</textarea>
                            <button type="submit" class="rounded bg-brand px-3 py-1.5 text-xs font-semibold text-white">
                                সংরক্ষণ
                            </button>
                            @if ($user->id === auth()->id())
                                <p class="text-2xs text-muted">নিজের ভূমিকা পরিবর্তন করা যাবে না।</p>
                            @endif
                        </form>

                        <form method="POST" action="{{ route('admin.users.password', $user) }}"
                              class="flex flex-wrap gap-2 border-t border-line pt-3">
                            @csrf @method('PUT')
                            <input type="password" name="password" required placeholder="নতুন পাসওয়ার্ড" autocomplete="new-password"
                                   class="min-w-0 flex-1 rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                            <input type="password" name="password_confirmation" required placeholder="নিশ্চিত করুন" autocomplete="new-password"
                                   class="min-w-0 flex-1 rounded border border-line-strong bg-canvas px-2 py-1.5 text-sm">
                            <button type="submit" class="rounded border border-line px-3 py-1.5 text-xs font-semibold text-body">
                                পাসওয়ার্ড রিসেট
                            </button>
                        </form>

                        @if ($user->id !== auth()->id())
                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                  onsubmit="return confirm('এই ব্যবহারকারীকে মুছে ফেলতে চান?')" class="border-t border-line pt-3">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold text-brand hover:underline">
                                    ব্যবহারকারী মুছে ফেলুন
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $users->links() }}</div>
    </section>

    <section class="lg:col-span-4">
        <form method="POST" action="{{ route('admin.users.store') }}"
              class="space-y-3 rounded-xl border border-line bg-surface p-5">
            @csrf
            <h2 class="font-headline text-base font-bold text-ink">নতুন ব্যবহারকারী</h2>

            <x-form.input name="name" label="পূর্ণ নাম" :required="true" />
            <x-form.input name="email" label="ইমেইল" type="email" :required="true" />
            <x-form.input name="phone" label="মোবাইল" type="tel" />
            <x-form.input name="designation" label="পদবি" />

            <div>
                <label for="n-role" class="mb-1.5 block text-sm font-semibold text-ink">ভূমিকা</label>
                <select id="n-role" name="role" required
                        class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink">
                    @foreach ($roles as $r)
                        <option value="{{ $r->value }}" @selected($r->value === 'reporter')>{{ $r->label() }}</option>
                    @endforeach
                </select>
            </div>

            <x-form.password name="password" label="পাসওয়ার্ড" autocomplete="new-password" />
            <x-form.password name="password_confirmation" label="নিশ্চিত করুন" autocomplete="new-password" />

            <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">
                যুক্ত করুন
            </button>
        </form>
    </section>
</div>
@endsection
