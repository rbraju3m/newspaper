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
 *
 * **A 404 under `/en/` is already an English 404, and nothing here does it.**
 * The `/en` group's own `/{category}` is constrained `.*`, so every path
 * under the prefix matches *something* inside the group — the catch-all if
 * nothing else — which means `locale:en` has run before the controller
 * throws. There is no need to infer the edition from the path, and a version
 * of this class that did was dead code: removing the inference changed no
 * test, which is how it was found.
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
