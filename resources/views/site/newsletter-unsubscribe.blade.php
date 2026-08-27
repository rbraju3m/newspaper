@extends('layouts.site')
@section('title', 'নিউজলেটার বন্ধ করুন — '.config('site.name_bn'))
@section('description', '')

{{-- A confirmation rather than a fait accompli. Reaching this page means a link
     was fetched, and a link is fetched by every mail scanner between the sender
     and the reader — so nothing has happened yet, and the button is what acts. --}}
@section('content')
    <div class="mx-auto max-w-lg px-4 py-12 lg:py-20">
        <div class="rounded-xl border border-line bg-surface p-6 text-center lg:p-8">
            <h1 class="font-headline text-2xl font-bold text-ink lg:text-3xl">নিউজলেটার বন্ধ করবেন?</h1>

            @if ($subscriber->unsubscribed_at)
                <p class="mt-3 text-sm leading-relaxed text-body">
                    এই ঠিকানায় আর নিউজলেটার পাঠানো হচ্ছে না।
                </p>

                <a href="{{ route('home') }}"
                   class="mt-6 inline-block rounded-lg bg-brand px-6 py-2.5 text-sm font-semibold
                          text-white transition hover:bg-brand-700">হোমপেজে ফিরুন</a>
            @else
                <p class="mt-3 text-sm leading-relaxed text-body">
                    নিশ্চিত করলে <span class="lat font-medium text-ink">{{ $subscriber->email }}</span>
                    ঠিকানায় আর কোনো নিউজলেটার পাঠানো হবে না।
                </p>

                <form method="POST" action="{{ route('newsletter.unsubscribe.click', $subscriber->token) }}"
                      class="mt-6 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-lg bg-brand px-6 py-2.5 text-sm font-semibold text-white
                                   transition hover:bg-brand-700 sm:w-auto">হ্যাঁ, বন্ধ করুন</button>

                    <a href="{{ route('home') }}"
                       class="w-full rounded-lg border border-line-strong px-6 py-2.5 text-sm font-semibold
                              text-ink transition hover:border-brand hover:text-brand sm:w-auto">থাক, পাঠাতে থাকুন</a>
                </form>

                <p class="mt-5 text-xs text-muted">
                    কম ইমেইল চাইলে
                    <a href="{{ route('account.preferences') }}" class="text-link underline">পছন্দ পরিবর্তন করুন</a> —
                    সপ্তাহে একবার বা কেবল বেছে নেওয়া বিভাগের খবর পাওয়া যায়।
                </p>
            @endif
        </div>
    </div>
@endsection
