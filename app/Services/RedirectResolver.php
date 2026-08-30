<?php

namespace App\Services;

use App\Models\Redirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Old-CMS URL preservation: turns a 404 into the redirect somebody recorded
 * for that path.
 *
 * **This is not middleware, and the two documents that called it one were
 * describing the wrong shape.** Two things rule that out.
 *
 * `/{category}` is constrained `.*`, so it swallows every depth of path —
 * `/2019/05/old-story.html` matches `category.show` and 404s from inside the
 * controller. Nothing reaches a routing-level 404, so a `Route::fallback()`
 * never fires and middleware would have to run *before* the router to see
 * anything at all.
 *
 * And running before the router means a lookup on every request to the site,
 * for ever, against a table that is **empty on every install that never
 * migrated a CMS**. Hooking the 404 instead costs exactly nothing on a request
 * that resolves, and one indexed lookup on a request that was already lost.
 *
 * The order that falls out of this is also the right one: a live page always
 * wins over a redirect recorded for the same path. A rule that shadowed real
 * content would be invisible until somebody noticed the wrong page.
 */
class RedirectResolver
{
    /**
     * What a row may actually answer with.
     *
     * `status` is an operator-supplied smallint with no constraint behind it,
     * and `redirect()->to($url, 500)` is not a thing. Anything else is read as
     * a typo and answered 301, which is what the column defaults to.
     */
    private const ALLOWED = [301, 302, 307, 308];

    public function resolve(Request $request): ?RedirectResponse
    {
        // A 301 on a POST is re-issued by the browser as a GET with no body,
        // which is a worse outcome than the 404. Admin and API 404s are not
        // legacy URLs and must keep answering as themselves.
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return null;
        }

        if ($request->is('admin', 'admin/*', 'api/*')) {
            return null;
        }

        try {
            return $this->lookUp($request);
        } catch (Throwable $e) {
            // Degrading to the 404 the request was already going to get is
            // safe; doing it silently is not. A redirects table that has
            // broken should reach whoever is on call, not just stop working.
            report($e);

            return null;
        }
    }

    private function lookUp(Request $request): ?RedirectResponse
    {
        $path = trim($request->path(), '/');
        $query = (string) $request->getQueryString();

        // A legacy CMS often keys on the query string — `/index.php?id=4021`
        // is one URL, not a page called `index.php`. Both forms go in one
        // `whereIn`, and both are offered with and without a leading slash so
        // it does not matter which way somebody stored them.
        $withQuery = $query === '' ? [] : [$path.'?'.$query, '/'.$path.'?'.$query];
        $bare = [$path, '/'.$path];

        $rows = Redirect::whereIn('from', [...$withQuery, ...$bare])
            ->get(['id', 'from', 'to', 'status']);

        if ($rows->isEmpty()) {
            return null;
        }

        // `redirects.from` is utf8mb4_unicode_ci, so this matched
        // case-insensitively — deliberate for legacy URLs, where the old CMS's
        // casing is rarely what somebody typed into the mapping file.
        $normalised = fn (string $from): string => trim($from, '/');
        $keyed = $rows->keyBy(fn (Redirect $r): string => mb_strtolower($normalised($r->from)));

        // The query-string form is the more specific match and wins.
        $redirect = $keyed->get(mb_strtolower($path.'?'.$query)) ?? $keyed->get(mb_strtolower($path));

        if (! $redirect) {
            return null;
        }

        $matchedQuery = $normalised($redirect->from) !== $path;
        $destination = $this->destinationFor($redirect, $matchedQuery ? '' : $query);

        // A rule pointing at its own URL is a browser redirect loop, and the
        // typo that produces one is ordinary. Everything past a single hop is
        // the operator's to get right — but this hop cannot be allowed to
        // break the page on its own.
        if ($destination === $request->fullUrl()) {
            return null;
        }

        // Query builder rather than `$redirect->increment()`: `hits` is not
        // fillable, and a hit is not an edit — bumping `updated_at` would lose
        // when the rule itself was last changed.
        DB::table('redirects')->where('id', $redirect->id)->increment('hits');

        return redirect()->to($destination, $this->statusFor($redirect));
    }

    /** Absolute `to` values are used as given; anything else is a local path. */
    private function destinationFor(Redirect $redirect, string $carryQuery): string
    {
        $to = trim($redirect->to);

        $destination = preg_match('#^https?://#i', $to) === 1
            ? $to
            : url('/'.ltrim($to, '/'));

        // `?page=3` on a section that moved should survive the move. Not
        // carried when the query string was part of what matched, and never
        // over a destination that brought its own.
        return $carryQuery !== '' && ! str_contains($destination, '?')
            ? $destination.'?'.$carryQuery
            : $destination;
    }

    private function statusFor(Redirect $redirect): int
    {
        return in_array($redirect->status, self::ALLOWED, true) ? $redirect->status : 301;
    }
}
