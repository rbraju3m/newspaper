@props(['block', 'data' => null])

<section class="rounded-xl border border-brand/25 bg-brand-50 p-5 dark:bg-surface">
    <x-ui.icon name="mail" class="h-7 w-7 text-brand" />
    <h2 class="mt-2 font-headline text-lg font-bold text-ink">দিনের সেরা খবর</h2>
    <p class="mt-1 text-sm text-body">প্রতিদিন সকালে বাছাই করা খবর সরাসরি আপনার ইনবক্সে।</p>

    <form action="{{ route('newsletter.subscribe') }}" method="POST" class="mt-3 space-y-2">
        @csrf
        <label for="nl-side" class="sr-only">ইমেইল ঠিকানা</label>
        <input id="nl-side" type="email" name="email" required placeholder="ইমেইল ঠিকানা"
               class="w-full rounded-lg border border-line-strong bg-surface px-3 py-2 text-sm
                      text-ink outline-none placeholder:text-muted focus:border-brand">
        <button type="submit"
                class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white
                       transition hover:bg-brand-700">
            সাবস্ক্রাইব করুন
        </button>
    </form>
</section>
