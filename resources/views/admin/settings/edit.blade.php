@extends('layouts.admin')
@section('title', 'সেটিংস')
@section('heading', 'সেটিংস')

@section('content')
<form method="POST" action="{{ route('admin.settings.update') }}" class="max-w-3xl space-y-5">
    @csrf @method('PUT')

    @php
        $groupLabels = [
            'general' => 'সাধারণ', 'imprint' => 'প্রতিষ্ঠান ও ইমপ্রিন্ট',
            'comments' => 'মন্তব্য', 'display' => 'প্রদর্শন', 'integration' => 'ইন্টিগ্রেশন',
        ];
    @endphp

    @foreach ($schema as $group => $fields)
        <section class="rounded-xl border border-line bg-surface p-5">
            <h2 class="font-headline text-base font-bold text-ink">{{ $groupLabels[$group] ?? $group }}</h2>

            <div class="mt-4 space-y-3">
                @foreach ($fields as $field)
                    @if ($field['type'] === 'bool')
                        <label class="flex cursor-pointer items-center gap-2.5 text-sm text-body">
                            <input type="checkbox" name="{{ $field['key'] }}" value="1"
                                   @checked((bool) $field['value'])
                                   class="rounded border-line-strong accent-[var(--color-brand)]">
                            {{ $field['label'] }}
                        </label>
                    @else
                        <div>
                            <label for="s-{{ $field['key'] }}" class="mb-1.5 block text-sm font-semibold text-ink">
                                {{ $field['label'] }}
                            </label>
                            <input id="s-{{ $field['key'] }}" name="{{ $field['key'] }}"
                                   type="{{ $field['type'] === 'int' ? 'number' : 'text' }}"
                                   value="{{ $field['value'] }}"
                                   @if ($field['type'] === 'int') min="0" @endif
                                   class="w-full rounded-lg border border-line-strong bg-canvas px-3 py-2 text-sm text-ink
                                          {{ $field['type'] === 'int' ? 'lat' : '' }}">
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach

    <button type="submit" class="rounded-lg bg-brand px-6 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
        সেটিংস সংরক্ষণ করুন
    </button>
</form>
@endsection
