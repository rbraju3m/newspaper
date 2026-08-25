@extends('layouts.site')
@section('title', 'সংরক্ষিত খবর — '.config('site.name_bn'))

@section('content')
<x-account.shell title="সংরক্ষিত খবর" active="bookmarks">
    @if ($articles->isEmpty())
        <x-ui.empty-state icon="bookmark" title="এখনো কিছু সংরক্ষণ করা হয়নি"
                          message="যেকোনো খবরের পাশে বুকমার্ক আইকনে ক্লিক করে পরে পড়ার জন্য রাখুন।">
            <a href="{{ route('latest') }}"
               class="mt-4 rounded-lg bg-brand px-5 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                সর্বশেষ খবর দেখুন
            </a>
        </x-ui.empty-state>
    @else
        <p class="mb-4 text-sm text-muted">@bn($articles->total()) টি খবর সংরক্ষিত</p>

        <div class="grid gap-x-6 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($articles as $article)
                <div class="relative">
                    <x-article.card :article="$article" variant="standard" />

                    {{-- Remove without leaving the page. --}}
                    <form method="POST" action="{{ route('account.bookmarks.destroy', $article) }}"
                          class="absolute right-2 top-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" aria-label="সংরক্ষণ থেকে সরান"
                                class="flex h-8 w-8 items-center justify-center rounded-full
                                       bg-black/60 text-white backdrop-blur transition hover:bg-brand">
                            <x-ui.icon name="close" class="h-4 w-4" />
                        </button>
                    </form>
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $articles->links() }}</div>
    @endif
</x-account.shell>
@endsection
