<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request into an edition.
 *
 * Applied to the `/en` route group only; everything else runs on
 * `APP_LOCALE`, which is `bn`. There is deliberately **no** negotiation from
 * `Accept-Language`, no cookie and no session: the URL is the whole truth
 * about which edition a page is.
 *
 * That is not laziness. A page whose language depends on a header is a page
 * with two bodies at one URL — the CDN caches whichever one it saw first, the
 * canonical tag contradicts half the responses, and a reader who shares a
 * link sends their counterpart something different from what they read. The
 * prefix costs one path segment and removes all of it.
 *
 * It also sets `app.locale` for the *rest* of the request, which is what makes
 * `__()`, `ArticleQuery` and the `@bn*` directives agree without any of them
 * being told separately.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, string $locale = Locale::DEFAULT): Response
    {
        if (in_array($locale, Locale::ALL, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
