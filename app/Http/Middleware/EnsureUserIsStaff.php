<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the whole /admin tree.
 *
 * Readers get a 404 rather than a 403: the existence of the admin panel is not
 * something a logged-in reader needs confirmed.
 */
class EnsureUserIsStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canAccessAdmin(), 404);

        return $next($request);
    }
}
