<footer class="mt-12 border-t-4 border-brand bg-surface">
    {{-- Newsletter — a reader-acquisition surface none of the benchmarks use well --}}
    <div class="border-b border-line bg-surface-2">
        <div class="mx-auto flex max-w-site flex-col items-center gap-4 px-4 py-8 md:flex-row md:justify-between">
            <div class="text-center md:text-left">
                <h2 class="font-headline text-xl font-bold text-ink">দিনের সেরা খবর ইনবক্সে</h2>
                <p class="mt-1 text-sm text-muted">প্রতিদিন সকালে বাছাই করা খবর পেতে সাবস্ক্রাইব করুন।</p>
            </div>

            <form action="{{ route('newsletter.subscribe') }}" method="POST"
                  class="flex w-full max-w-md items-center gap-2">
                @csrf
                <label for="nl-email" class="sr-only">ইমেইল ঠিকানা</label>
                <input id="nl-email" type="email" name="email" required placeholder="আপনার ইমেইল ঠিকানা"
                       class="min-w-0 flex-1 rounded-lg border border-line-strong bg-surface px-3.5 py-2.5
                              text-sm text-ink outline-none placeholder:text-muted focus:border-brand">
                <button type="submit"
                        class="shrink-0 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white
                               transition hover:bg-brand-700">
                    সাবস্ক্রাইব
                </button>
            </form>
        </div>
    </div>

    <div class="mx-auto max-w-site px-4 py-10">
        <div class="grid grid-cols-2 gap-8 md:grid-cols-4 lg:grid-cols-5">
            <div class="col-span-2 lg:col-span-1">
                <x-ui.logo class="h-12 w-auto" />
                <p class="mt-3 text-sm leading-relaxed text-muted">{{ config('site.description') }}</p>
                <div class="mt-4 flex items-center gap-3">
                    @foreach (config('site.social') as $name => $url)
                        @if ($url)
                            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"
                               aria-label="{{ ucfirst($name) }}"
                               class="rounded-md border border-line p-2 text-muted transition
                                      hover:border-brand hover:text-brand">
                                <x-ui.icon name="{{ $name }}" class="h-4 w-4" />
                            </a>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- Category columns, chunked so the footer balances at any count --}}
            @foreach ($allCategories->take(24)->chunk(8) as $chunk)
                <nav aria-label="বিভাগ">
                    <h3 class="font-headline text-sm font-bold uppercase tracking-wide text-ink">বিভাগ</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($chunk as $category)
                            <li>
                                <a href="{{ route('category.show', $category->path) }}"
                                   class="text-sm text-muted hover:text-brand">{{ $category->name }}</a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endforeach

            <nav aria-label="প্রতিষ্ঠান">
                <h3 class="font-headline text-sm font-bold uppercase tracking-wide text-ink">প্রতিষ্ঠান</h3>
                <ul class="mt-3 space-y-2">
                    @foreach ([
                        'about' => 'আমাদের সম্পর্কে',
                        'contact' => 'যোগাযোগ',
                        'advertise' => 'বিজ্ঞাপন',
                        'privacy' => 'গোপনীয়তা নীতি',
                        'terms' => 'ব্যবহারের শর্তাবলি',
                        'comment-policy' => 'মন্তব্য নীতি',
                    ] as $slug => $label)
                        <li>
                            <a href="{{ route('page.show', $slug) }}"
                               class="text-sm text-muted hover:text-brand">{{ $label }}</a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </div>

        {{-- Imprint — required on Bangladeshi mastheads --}}
        <div class="mt-8 border-t border-line pt-6 text-sm text-muted">
            @if (config('site.editor'))
                <p><span class="font-semibold text-body">সম্পাদক:</span> {{ config('site.editor') }}</p>
            @endif
            @if (config('site.address'))
                <p class="mt-1">{{ config('site.address') }}</p>
            @endif
            <p class="mt-1">
                @if (config('site.phone')) ফোন: {{ App\Support\Bangla::digits(config('site.phone')) }} @endif
                @if (config('site.email')) · ইমেইল: {{ config('site.email') }} @endif
            </p>
        </div>

        <div class="mt-6 flex flex-col items-center justify-between gap-2 border-t border-line pt-5
                    text-xs text-muted sm:flex-row">
            <p>© @bn(date('Y')) {{ config('site.name_bn') }} — সর্বস্বত্ব সংরক্ষিত।</p>
            <p>ডেভেলপ করেছে <span class="font-medium text-body">{{ config('site.name_en') }}</span></p>
        </div>
    </div>
</footer>
