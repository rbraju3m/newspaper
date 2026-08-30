<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Epaper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public e-paper reader.
 *
 * `epapers` is unique on `(date, edition)`, not on `date`, so the admin has
 * always been able to publish a Dhaka issue and a Chittagong issue for the
 * same day. The reader's URL carried only the date, and the query behind it
 * was an unordered `firstOrFail()` — so the second edition was unreachable,
 * and *which* one a reader got was whatever InnoDB happened to return.
 *
 * `?edition=` names one. Leaving it off is not "any of them": it resolves to
 * the house edition, and only falls back to the alphabetically first when
 * that day has no house issue. Deterministic either way, which is the half
 * that matters even on a paper that will only ever run one edition.
 */
class EpaperController extends Controller
{
    /** How many back issues the rail offers. */
    private const RAIL = 14;

    public function index(Request $request): Response
    {
        $edition = $this->requestedEdition($request);

        if ($edition === Epaper::defaultEdition()) {
            return redirect()->to(route('epaper.index'), 301);
        }

        $epaper = $this->preferHouseEdition(
            Epaper::published()
                ->when($edition !== null, fn (Builder $q) => $q->where('edition', $edition))
                ->orderByDesc('date')
        )->with('pages')->first();

        // An install with nothing published — and, with `?edition=` set, one
        // that has never run that edition. Both are the empty state rather
        // than a 404: the hub is a place, not a document.
        return $epaper
            ? $this->render($epaper)
            : response()->view('site.epaper', [
                'epaper' => null,
                'recent' => collect(),
                'editions' => collect(),
            ]);
    }

    public function show(Request $request, string $date): Response
    {
        $edition = $this->requestedEdition($request);

        $epaper = $this->preferHouseEdition(
            Epaper::published()
                ->whereDate('date', $date)
                ->when($edition !== null, fn (Builder $q) => $q->where('edition', $edition))
        )->with('pages')->firstOrFail();

        // One URL per issue. An explicit `?edition=` naming the house edition
        // is the same paper as the clean form, so it redirects rather than
        // serving identical bytes at two addresses — the same rule
        // `ArticleController` applies to a stale slug.
        //
        // Compared against the *requested* edition rather than against
        // `$request->url()`: that method drops the query string, so a
        // legitimate `?edition=dhaka` would never equal the canonical URL and
        // the redirect would loop.
        if ($edition === Epaper::defaultEdition()) {
            return redirect()->to($epaper->url, 301);
        }

        return $this->render($epaper);
    }

    /**
     * Breaks the tie between two editions of the same day, and nothing else —
     * ordering by date is the caller's job, and has to be applied first.
     *
     * The house edition wins; failing that the editions sort by name, so a
     * day holding only `chittagong` and `dhaka` still resolves the same way
     * on every request. `firstOrFail()` on its own does not.
     */
    private function preferHouseEdition(Builder $query): Builder
    {
        return $query
            ->orderByRaw('edition = ? desc', [Epaper::defaultEdition()])
            ->orderBy('edition');
    }

    /**
     * The edition asked for, or null for "whichever is the house one".
     *
     * Not validated against `site.epaper_editions`: the admin accepts any
     * string up to 60 characters, so an edition the config does not label is
     * still a real issue and has to stay reachable. An edition nobody
     * published matches no row and 404s, which is the right answer for a
     * client-supplied name. The length cap is the column's.
     */
    private function requestedEdition(Request $request): ?string
    {
        $edition = $request->query('edition');

        if (! is_string($edition)) {
            return null;
        }

        $edition = trim($edition);

        return $edition === '' || mb_strlen($edition) > 60 ? null : $edition;
    }

    private function render(Epaper $epaper): Response
    {
        return response()->view('site.epaper', [
            'epaper' => $epaper,
            // Scoped to the edition being read. Unscoped, a paper running two
            // editions gets a rail of duplicated dates whose links all lead
            // to the same issue.
            'recent' => Epaper::published()
                ->where('edition', $epaper->edition)
                ->orderByDesc('date')
                ->limit(self::RAIL)
                ->get(['id', 'date', 'edition', 'cover']),
            'editions' => $this->editionsOn($epaper->date),
        ]);
    }

    /**
     * Every edition published on one day, house edition first.
     *
     * One row on a single-edition paper, which is what the view checks before
     * drawing a switcher nobody needs.
     */
    private function editionsOn(Carbon $date): Collection
    {
        return $this->preferHouseEdition(
            Epaper::published()->whereDate('date', $date)
        )->get(['id', 'date', 'edition']);
    }
}
