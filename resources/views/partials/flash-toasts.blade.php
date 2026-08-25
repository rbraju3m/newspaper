@php
    $flashes = collect()
        ->when(session('status'), fn ($c) => $c->push(['success', session('status')]))
        ->when($errors->any() && ! request()->routeIs('login', 'register', 'password.*'),
               fn ($c) => $c->push(['error', $errors->first()]));
@endphp

@if ($flashes->isNotEmpty())
    {{-- Pushed after Alpine boots so the store exists. --}}
    <script type="application/json" id="flash-payload">{!! $flashes->toJson() !!}</script>
    <script>
        document.addEventListener('alpine:initialized', () => {
            try {
                const el = document.getElementById('flash-payload');
                JSON.parse(el.textContent).forEach(([type, message]) => {
                    window.Alpine.store('toast').push(message, type, 5000);
                });
            } catch (e) {}
        });
    </script>
@endif
