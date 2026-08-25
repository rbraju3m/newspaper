<!DOCTYPE html>
<html lang="bn" class="scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <script>
        (function () {
            try {
                var m = JSON.parse(localStorage.getItem('np_theme') || '"system"');
                var dark = m === 'dark' || (m === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>

    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-canvas text-body antialiased">
    <div class="flex min-h-screen flex-col">
        <header class="border-b border-line bg-surface">
            <div class="mx-auto flex max-w-site items-center justify-between px-4 py-4">
                <a href="{{ route('home') }}" aria-label="প্রচ্ছদে ফিরুন">
                    <x-ui.logo class="h-9 w-auto" />
                </a>
                <div class="flex items-center gap-2">
                    <x-ui.theme-toggle />
                    <a href="{{ route('home') }}"
                       class="flex items-center gap-1.5 rounded-lg border border-line px-3 py-1.5
                              text-sm font-medium text-body hover:border-brand hover:text-brand">
                        <x-ui.icon name="arrow-left" class="h-3.5 w-3.5" /> প্রচ্ছদ
                    </a>
                </div>
            </div>
        </header>

        <main class="flex flex-1 items-center justify-center px-4 py-10">
            <div class="w-full max-w-md">
                @yield('content')
            </div>
        </main>

        <footer class="border-t border-line bg-surface py-5">
            <p class="text-center text-xs text-muted">
                © @bn(date('Y')) {{ config('site.name_bn') }} —
                <a href="{{ route('page.show', 'privacy') }}" class="hover:text-brand">গোপনীয়তা নীতি</a> ·
                <a href="{{ route('page.show', 'terms') }}" class="hover:text-brand">শর্তাবলি</a>
            </p>
        </footer>
    </div>
</body>
</html>
