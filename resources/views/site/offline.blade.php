<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>অফলাইন — {{ config('site.name_bn') }}</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-canvas px-4 text-body antialiased">
    <div class="w-full max-w-md text-center">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-brand text-white">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
                 stroke-linecap="round" class="h-8 w-8" aria-hidden="true">
                <path d="M2 8.8a15 15 0 0120 0M5 12.5a10 10 0 0114 0M8.5 16a5 5 0 017 0"/>
                <path d="M12 20h.01"/><path d="M3 3l18 18"/>
            </svg>
        </div>

        <h1 class="mt-5 font-headline text-2xl font-bold text-ink">আপনি অফলাইনে আছেন</h1>
        <p class="mt-2 text-base text-body">
            ইন্টারনেট সংযোগ পাওয়া যাচ্ছে না। আগে পড়া খবরগুলো এখনো দেখতে পারবেন।
        </p>

        <div class="mt-6 flex justify-center gap-2">
            <button type="button" onclick="window.location.reload()"
                    class="rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white">
                আবার চেষ্টা করুন
            </button>
            <a href="{{ url('/') }}"
               class="rounded-lg border border-line-strong px-5 py-2.5 text-sm font-semibold text-ink">
                প্রচ্ছদ
            </a>
        </div>

        <p class="mt-6 text-xs text-muted">
            সংযোগ ফিরে এলে পাতাটি নিজে থেকেই আবার লোড হবে।
        </p>
    </div>

    <script>
        // Reload the moment connectivity returns, so the reader does not have
        // to notice and tap themselves.
        window.addEventListener('online', () => window.location.reload());
    </script>
</body>
</html>
