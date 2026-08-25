@extends('layouts.site')
@section('title', 'পড়ার ইতিহাস — '.config('site.name_bn'))

@section('content')
<x-account.shell title="পড়ার ইতিহাস" active="history">
    @if ($articles->isEmpty())
        <x-ui.empty-state icon="clock" title="কোনো ইতিহাস নেই"
                          message="আপনি যেসব খবর পড়বেন সেগুলো এখানে জমা হবে।" />
    @else
        <div class="mb-4 flex items-center justify-between">
            <p class="text-sm text-muted">@bn($articles->total()) টি খবর পড়া হয়েছে</p>

            <form method="POST" action="{{ route('account.history.clear') }}"
                  x-data @submit="$event.submitter.disabled = true">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-lg border border-line px-3 py-1.5 text-xs font-semibold
                               text-body transition hover:border-brand hover:text-brand">
                    সব মুছে ফেলুন
                </button>
            </form>
        </div>

        <div class="space-y-4">
            @foreach ($articles as $article)
                <div class="flex items-start gap-3 rounded-xl border border-line bg-surface p-3">
                    <div class="min-w-0 flex-1">
                        <x-article.card :article="$article" variant="list" />

                        {{-- How far they actually got, from reading_history.progress --}}
                        @php $progress = (int) ($article->pivot->progress ?? 0); @endphp
                        @if ($progress > 0)
                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1 flex-1 overflow-hidden rounded-full bg-surface-2">
                                    <div class="h-full rounded-full bg-brand" style="width: {{ $progress }}%"></div>
                                </div>
                                <span class="lat shrink-0 text-2xs text-muted">
                                    {{ $progress >= 95 ? 'সম্পূর্ণ পড়া হয়েছে' : App\Support\Bangla::digits($progress).'%' }}
                                </span>
                            </div>
                        @endif
                    </div>

                    <form method="POST" action="{{ route('account.history.destroy', $article) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" aria-label="ইতিহাস থেকে সরান"
                                class="rounded-md p-1.5 text-muted transition hover:text-brand">
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
