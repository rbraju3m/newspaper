@extends('errors.layout')

@section('code', '৪০৪')
@section('heading', 'পাতাটি খুঁজে পাওয়া যায়নি')
@section('message', 'ঠিকানাটি ভুল হতে পারে, অথবা খবরটি সরিয়ে নেওয়া হয়েছে। নিচে খুঁজে দেখতে পারেন।')

@section('extra')
    {{-- A newspaper's 404 is a search box. The reader arrived looking for
         something specific; sending them to the front page alone wastes that. --}}
    <form action="{{ route('search') }}" method="get" class="flex gap-2">
        <label for="e404-q" class="sr-only">খবর খুঁজুন</label>
        <input id="e404-q" name="q" type="search" required
               placeholder="খবর খুঁজুন…"
               class="min-w-0 flex-1 rounded-lg border border-line-strong bg-surface px-4 py-2.5 text-sm
                      text-ink outline-none placeholder:text-muted focus:border-brand">
        <button type="submit"
                class="rounded-lg border border-line-strong bg-surface-2 px-4 py-2.5 text-sm font-semibold
                       text-ink transition hover:border-brand hover:text-brand">
            খুঁজুন
        </button>
    </form>
@endsection

@section('actions')
    <a href="{{ route('archive') }}"
       class="rounded-lg border border-line-strong px-5 py-2.5 text-sm font-semibold text-body
              transition hover:border-brand hover:text-brand">
        আর্কাইভ দেখুন
    </a>
@endsection
