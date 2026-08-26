{{-- Self-contained shell for the codes that mean the application itself is in
     trouble.

     Deliberately does NOT extend layouts.site. That layout's header and footer
     are fed by view composers that query the category tree, and the cache
     store is the database — so at exactly the moment a 500 is being rendered,
     none of it can be relied on. A layout that throws while rendering an error
     page loses the error page.

     For the same reason the CSS is inline rather than @vite: `artisan down`
     pre-renders 503 to a static file that is served without booting the
     application, and a deploy that rebuilds assets between `down` and `up`
     would leave that file pointing at a hashed bundle which no longer exists.

     No JavaScript, no fonts, no images, no database. One file. --}}
<!DOCTYPE html>
<html lang="bn" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('heading') — {{ config('site.name_bn', config('app.name')) }}</title>
    <style>
        /* The same tokens as resources/css/app.css, inlined. Theme follows the
           system preference: without JavaScript there is no stored choice to
           read, and guessing wrong is worse than following the OS. */
        :root {
            --canvas: #F7F8FA; --surface: #FFFFFF; --ink: #14171A;
            --body: #3A4046; --muted: #616874; --line: #E5E7EB; --brand: #C8102E;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: #0E1113; --surface: #171A1D; --ink: #F2F4F6;
                --body: #C3C9CF; --muted: #8B939B; --line: #2A3034; --brand: #FF4D68;
            }
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--canvas);
            color: var(--body);
            font-family: 'Noto Sans Bengali', 'Inter', ui-sans-serif, system-ui, sans-serif;
            line-height: 1.7;
        }
        .card {
            width: 100%;
            max-width: 34rem;
            padding: 2.5rem 2rem;
            text-align: center;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 0.75rem;
        }
        .code {
            margin: 0;
            font-size: 3.5rem;
            font-weight: 700;
            line-height: 1;
            color: var(--brand);
            font-variant-numeric: tabular-nums;
        }
        h1 {
            margin: 1rem 0 0;
            font-family: 'Noto Serif Bengali', 'Inter', ui-serif, Georgia, serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--ink);
        }
        p { margin: 0.75rem 0 0; font-size: 1rem; }
        .note { margin-top: 1.5rem; font-size: 0.8125rem; color: var(--muted); }
        .brandline { margin-top: 2rem; font-size: 0.8125rem; color: var(--muted); }
        a { color: var(--brand); }
    </style>
</head>
<body>
    <main class="card">
        <p class="code">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>

        @hasSection('note')
            <p class="note">@yield('note')</p>
        @endif

        <p class="brandline">{{ config('site.name_bn', config('app.name')) }}</p>
    </main>
</body>
</html>
