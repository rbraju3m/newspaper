<?php

use App\Http\Middleware\EnsureUserIsStaff;
use App\Services\ErrorAlerter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'staff' => EnsureUserIsStaff::class,
        ]);

        // RFC 8058 one-click unsubscribe. Gmail and Outlook POST this
        // themselves from their own chrome — no page was rendered, so there is
        // no session and no token to send. The 64-character subscriber token in
        // the URL is the credential, and the only thing the request can do is
        // stop that address receiving mail.
        $middleware->validateCsrfTokens(except: [
            'newsletter/unsubscribe/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Everything reportable goes to the `errors` channel as JSON and, the
        // first time each fault is seen, out to whoever is on call. Laravel has
        // already declined to report 404s, validation failures and the rest of
        // the ordinary noise before a callback registered here is reached.
        //
        // Not ->stop(): the normal log line is still wanted.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorAlerter::class)->report($e);
        });
    })->create();
