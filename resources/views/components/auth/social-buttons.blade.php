@php
    // Only render providers that are actually configured — a dead button is
    // worse than no button.
    $providers = collect(['google' => 'গুগল', 'facebook' => 'ফেসবুক'])
        ->filter(fn ($label, $key) => config("services.$key.client_id"));
@endphp

@if ($providers->isNotEmpty())
    <div class="my-5 flex items-center gap-3">
        <span class="h-px flex-1 bg-line"></span>
        <span class="text-xs text-muted">অথবা</span>
        <span class="h-px flex-1 bg-line"></span>
    </div>

    <div class="grid gap-2 {{ $providers->count() > 1 ? 'sm:grid-cols-2' : '' }}">
        @foreach ($providers as $key => $label)
            <a href="{{ route('oauth.redirect', $key) }}"
               class="flex items-center justify-center gap-2 rounded-lg border border-line-strong
                      bg-surface px-4 py-2.5 text-sm font-semibold text-ink transition
                      hover:border-brand hover:text-brand">
                <x-ui.icon name="{{ $key }}" class="h-4 w-4" />
                {{ $label }}
            </a>
        @endforeach
    </div>
@endif
