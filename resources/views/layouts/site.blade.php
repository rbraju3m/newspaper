<!DOCTYPE html>
<html lang="bn" dir="ltr" class="scroll-pt-24">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Applied before paint so the page never flashes the wrong theme. --}}
    <script>
        (function () {
            try {
                var m = JSON.parse(localStorage.getItem('np_theme') || '"system"');
                var dark = m === 'dark' || (m === 'system' &&
                    window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
                var fs = JSON.parse(localStorage.getItem('np_fs') || '"md"');
                document.documentElement.dataset.fs = fs;
            } catch (e) {}
        })();
    </script>

    <title>@yield('title', config('app.name'))</title>
    <meta name="description" content="@yield('description', config('site.description', ''))">
    <link rel="canonical" href="@yield('canonical', url()->current())">

    {{-- Open Graph / Twitter — every reference site under-invests here. --}}
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:locale" content="bn_BD">
    <meta property="og:title" content="@yield('og_title', View::yieldContent('title'))">
    <meta property="og:description" content="@yield('og_description', View::yieldContent('description'))">
    <meta property="og:url" content="@yield('canonical', url()->current())">
    <meta property="og:image" content="@yield('og_image', asset('images/og-default.jpg'))">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="{{ asset('images/favicon-32.png') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('images/icon-180.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="{{ config('site.name_bn') }}">

    {{-- Read by the pwa store. Scope is derived from the worker's own URL, not
         from APP_URL: asset() follows the actual request root, so deriving them
         separately breaks whenever the two disagree (dev server vs subdirectory
         install), and a scope the worker cannot claim silently controls nothing. --}}
    @php
        $swUrl = asset('sw.js');
        $swScope = rtrim(dirname(parse_url($swUrl, PHP_URL_PATH)), '/').'/';
    @endphp
    <meta name="sw-url" content="{{ $swUrl }}">
    <meta name="sw-scope" content="{{ $swScope }}">
    <meta name="theme-color" content="#C8102E" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0E1113" media="(prefers-color-scheme: dark)">

    <link rel="alternate" type="application/rss+xml" title="{{ config('app.name') }} RSS"
          href="{{ url('/rss') }}">

    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('schema')
</head>

<body class="min-h-screen bg-canvas text-body antialiased"
      x-data="shortcuts({{ Js::from([
          'home' => route('home'),
          'latest' => route('latest'),
          'bookmarks' => route('account.bookmarks'),
      ]) }})">
    <a href="#main"
       class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100]
              focus:rounded-md focus:bg-brand focus:px-4 focus:py-2 focus:text-white">
        মূল বিষয়বস্তুতে যান
    </a>

    @include('partials.header')

    <main id="main" class="min-h-[60vh]">
        @yield('content')
    </main>

    @include('partials.footer')

    @include('partials.app-chrome')
    @include('partials.flash-toasts')

    @stack('modals')
    @stack('scripts')
</body>
</html>
