<?php

namespace Tests\Feature;

use App\Models\Epaper;
use App\Models\EpaperPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The public e-paper reader — `/epaper` and `/epaper/{date}`.
 *
 * `EpaperAdminTest` proves the desk can build an issue and that a published
 * one answers 200 while an unpublished one 404s. This is the other side: what
 * a reader actually gets. The reader is the only surface in the application
 * that renders a whole issue, and the shapes it has to survive — no issue at
 * all, an issue with no pages yet, a page with no thumbnail — are exactly the
 * ones a newsroom produces at 4am while the paper is still being uploaded.
 *
 * `/epaper` and `/epaper/{date}` resolve an issue differently and then render
 * it identically, so anything asserted about one holds for the other. They are
 * only distinguished where the resolution itself is the thing under test —
 * which, since editions arrived, is more often than it used to be.
 */
class EpaperReaderTest extends TestCase
{
    use RefreshDatabase;

    // ── Resolving an issue ───────────────────────────────────────────────

    public function test_the_hub_opens_the_newest_published_issue(): void
    {
        $this->issue('2026-08-18', pages: 2);
        $newest = $this->issue('2026-08-20', pages: 2);
        $this->issue('2026-08-19', pages: 2);

        // The rail lists every recent date, so the issue actually opened has
        // to be read off the header — the weekday is rendered there and
        // nowhere else on the page.
        $this->get('/epaper')
            ->assertOk()
            ->assertSee('২০ আগস্ট ২০২৬, বৃহস্পতিবার', false)
            ->assertDontSee('বুধবার');

        $this->assertSame('2026-08-20', $newest->date->toDateString());
    }

    /**
     * Tomorrow's paper is normally uploaded the night before and held. The hub
     * has to keep showing today's until somebody publishes it.
     */
    public function test_an_unpublished_newer_issue_does_not_take_over_the_hub(): void
    {
        $this->issue('2026-08-20', pages: 2);
        $this->issue('2026-08-21', pages: 2, published: false);

        $this->get('/epaper')
            ->assertOk()
            ->assertSee('২০ আগস্ট ২০২৬, বৃহস্পতিবার', false)
            ->assertDontSee('২১ আগস্ট ২০২৬', false);
    }

    public function test_a_back_issue_is_reachable_by_its_own_date(): void
    {
        $this->issue('2026-08-20', pages: 2);
        $this->issue('2026-08-14', pages: 2);

        $this->get('/epaper/2026-08-14')
            ->assertOk()
            ->assertSee('১৪ আগস্ট ২০২৬', false)
            ->assertSee('শুক্রবার');
    }

    public function test_a_date_with_no_issue_is_a_404(): void
    {
        $this->issue('2026-08-20', pages: 2);

        $this->get('/epaper/2026-08-13')->assertNotFound();
    }

    /**
     * The route constrains the date to `\d{4}-\d{2}-\d{2}`, so anything else
     * under `/epaper/` falls through to the catch-all routes at the bottom of
     * `web.php` — which is the whole reason those two are registered last.
     *
     * Asserted by matching against the route collection rather than by status
     * code, because the status depends on what else is in the database:
     * `/epaper/12` is `article.show` with category `epaper` and article 12, so
     * it is a 404 on an empty fixture and a 301 to the canonical path on the
     * seeded box. The routing is the invariant; the status is not.
     * `php artisan route:list` sorts alphabetically and cannot show this.
     */
    public function test_a_malformed_date_falls_through_to_the_catch_all_routes(): void
    {
        $matches = fn (string $path): ?string => Route::getRoutes()
            ->match(Request::create($path, 'GET'))
            ->getName();

        $this->assertSame('epaper.show', $matches('/epaper/2026-08-20'));

        // A single-digit month, a word, and a bare id: none of them are dates.
        $this->assertSame('category.show', $matches('/epaper/2026-8-1'));
        $this->assertSame('category.show', $matches('/epaper/latest'));
        $this->assertSame('article.show', $matches('/epaper/12'));
    }

    public function test_a_malformed_date_is_not_a_500(): void
    {
        $this->issue('2026-08-20', pages: 2);

        foreach (['/epaper/2026-8-1', '/epaper/latest'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    /**
     * A date that satisfies the route pattern but is not a real day reaches
     * `whereDate()` and goes to MySQL. It has to come back empty rather than
     * raising, or a crawler walking the archive turns into a page of 500s.
     */
    public function test_an_impossible_date_is_a_404_and_not_a_500(): void
    {
        $this->issue('2026-08-20', pages: 2);

        $this->get('/epaper/2026-13-45')->assertNotFound();
        $this->get('/epaper/0000-00-00')->assertNotFound();
    }

    // ── The pages ────────────────────────────────────────────────────────

    /**
     * What this pins is the rendered order, not the `orderBy` clause behind
     * it. Removing `->orderBy('page_number')` from `Epaper::pages()` does not
     * fail this test, because `unique(epaper_id, page_number)` is the index
     * MySQL resolves the `epaper_id = ?` lookup through and it hands the rows
     * back in that order anyway. A *wrong* explicit order does fail it —
     * `orderBy('id')` renders 3, 1, 2 here.
     *
     * That is worth stating rather than assuming: the reader is ordered
     * correctly by two independent mechanisms, and a test that appears to
     * guard one of them only guards the outcome.
     */
    public function test_pages_render_in_page_number_order_whatever_order_they_were_created(): void
    {
        $epaper = $this->issue('2026-08-20');

        // Inserted back to front: `pages()` orders by `page_number`, and the
        // rows' own order is the reverse of what the reader must see.
        foreach ([3, 1, 2] as $number) {
            $this->page($epaper, $number);
        }

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSeeInOrder(['পৃষ্ঠা ১', 'পৃষ্ঠা ২', 'পৃষ্ঠা ৩'], false);
    }

    public function test_a_page_links_to_the_full_image_and_shows_its_thumbnail(): void
    {
        $epaper = $this->issue('2026-08-20');
        $this->page($epaper, 1);

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee(asset('storage/uploads/epaper/p1.jpg'), false)
            ->assertSee(asset('storage/uploads/epaper/p1-thumb.webp'), false);
    }

    /**
     * The admin always writes a thumbnail, but a row imported by hand or by a
     * future migration may not have one. Falling back to the full page is
     * heavy; falling back to `storage/` with nothing after it is a broken
     * image on every page of the issue.
     */
    public function test_a_page_with_no_thumbnail_falls_back_to_the_full_image(): void
    {
        $epaper = $this->issue('2026-08-20');
        $this->page($epaper, 1, thumbnail: null);

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee('src="'.asset('storage/uploads/epaper/p1.jpg').'"', false)
            ->assertDontSee('src="'.asset('storage/').'"', false);
    }

    public function test_a_section_label_is_shown_when_the_desk_set_one(): void
    {
        $epaper = $this->issue('2026-08-20');
        $this->page($epaper, 1, section: 'খেলা');
        $this->page($epaper, 2);

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSeeInOrder(['খেলা', 'পৃষ্ঠা ১'], false);
    }

    // ── The whole-issue PDF ──────────────────────────────────────────────

    public function test_the_pdf_download_appears_only_when_there_is_one(): void
    {
        $withPdf = $this->issue('2026-08-20', pages: 1, attributes: [
            'pdf' => 'uploads/epaper/2026-08-20.pdf',
        ]);
        $this->issue('2026-08-19', pages: 1);

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee('পিডিএফ ডাউনলোড')
            ->assertSee(asset('storage/'.$withPdf->pdf), false);

        $this->get('/epaper/2026-08-19')
            ->assertOk()
            ->assertDontSee('পিডিএফ ডাউনলোড');
    }

    // ── The back-issue rail ──────────────────────────────────────────────

    public function test_the_rail_lists_recent_published_issues_newest_first(): void
    {
        foreach (['2026-08-18', '2026-08-20', '2026-08-19'] as $date) {
            $this->issue($date, pages: 1);
        }

        $this->get('/epaper')
            ->assertOk()
            ->assertSee('আগের সংখ্যা')
            ->assertSeeInOrder(['২০ আগস্ট ২০২৬', '১৯ আগস্ট ২০২৬', '১৮ আগস্ট ২০২৬'], false);
    }

    public function test_the_rail_stops_at_fourteen_issues(): void
    {
        // Sixteen consecutive days, newest 2026-08-20. The rail takes 14, so
        // the two oldest must not be listed.
        for ($i = 0; $i < 16; $i++) {
            $this->issue(
                \Illuminate\Support\Carbon::parse('2026-08-20')->subDays($i)->toDateString(),
                pages: 1,
            );
        }

        $response = $this->get('/epaper')->assertOk();

        $response->assertSee(route('epaper.show', '2026-08-07'), false);
        $response->assertDontSee(route('epaper.show', '2026-08-06'), false);
        $response->assertDontSee(route('epaper.show', '2026-08-05'), false);
    }

    public function test_the_rail_does_not_offer_an_unpublished_issue(): void
    {
        $this->issue('2026-08-20', pages: 1);
        $this->issue('2026-08-19', pages: 1, published: false);

        $this->get('/epaper')
            ->assertOk()
            ->assertDontSee(route('epaper.show', '2026-08-19'), false);
    }

    /**
     * The rail marks the issue being read so a reader can tell where they are
     * in a column of fourteen near-identical dates.
     */
    public function test_the_rail_marks_the_issue_being_read(): void
    {
        $this->issue('2026-08-20', pages: 1);
        $this->issue('2026-08-19', pages: 1);

        $html = $this->get('/epaper/2026-08-19')->assertOk()->getContent();

        $current = $this->railLink($html, '2026-08-19');
        $other = $this->railLink($html, '2026-08-20');

        $this->assertStringContainsString('bg-brand', $current);
        $this->assertStringNotContainsString('bg-brand', $other);
    }

    // ── The shapes a half-built issue takes ──────────────────────────────

    /**
     * A fresh install has no e-paper at all. `index()` takes its other branch
     * here — the one that never calls `show()` — and hands the view a null
     * issue and an empty rail.
     */
    public function test_the_hub_renders_an_empty_state_when_nothing_is_published(): void
    {
        $this->get('/epaper')
            ->assertOk()
            ->assertSee('ই-পেপার এখনো প্রকাশিত হয়নি')
            ->assertDontSee('আগের সংখ্যা');
    }

    public function test_an_issue_published_before_its_pages_are_uploaded_renders_the_empty_state(): void
    {
        $this->issue('2026-08-20');

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee('২০ আগস্ট ২০২৬', false)
            ->assertSee('ই-পেপার এখনো প্রকাশিত হয়নি');
    }

    // ── Editions ─────────────────────────────────────────────────────────

    /**
     * Two editions on one day are legal — the admin's unique key is
     * `(date, edition)`, not `date`. The reader's URL used to carry only the
     * date, and the query behind it was an unordered `firstOrFail()`, so the
     * second edition was unreachable and which one you got was whatever
     * InnoDB returned.
     *
     * A page still holds exactly one issue. What changed is that the other
     * one has an address.
     */
    public function test_each_edition_of_one_day_is_reachable_by_name(): void
    {
        $this->page($this->edition('2026-08-20', 'main'), 1, section: 'প্রধান পাতা');
        $this->page($this->edition('2026-08-20', 'dhaka'), 1, section: 'ঢাকার পাতা');

        $this->get('/epaper/2026-08-20?edition=dhaka')
            ->assertOk()
            ->assertSee('ঢাকার পাতা')
            ->assertDontSee('প্রধান পাতা');

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee('প্রধান পাতা')
            ->assertDontSee('ঢাকার পাতা');
    }

    /**
     * The bare date is not "any of them". It resolves to the house edition —
     * the first key of `site.epaper_editions` — whatever order the rows were
     * created in, which the old unordered `firstOrFail()` did not.
     *
     * Created back to front on purpose: `dhaka` has the lower id, so a query
     * with no ordering hands it back first.
     */
    public function test_the_bare_date_resolves_to_the_house_edition_whatever_order_the_rows_were_created(): void
    {
        $this->page($this->edition('2026-08-20', 'dhaka'), 1, section: 'ঢাকার পাতা');
        $this->page($this->edition('2026-08-20', 'main'), 1, section: 'প্রধান পাতা');

        $this->assertSame('main', Epaper::defaultEdition());

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee('প্রধান পাতা')
            ->assertDontSee('ঢাকার পাতা');
    }

    /**
     * A day the house edition skipped still has to resolve to the same issue
     * on every request. Alphabetical is an arbitrary choice; being able to
     * predict it is not.
     */
    public function test_a_day_with_no_house_edition_resolves_alphabetically_and_not_by_id(): void
    {
        $this->page($this->edition('2026-08-20', 'dhaka'), 1, section: 'ঢাকার পাতা');
        $this->page($this->edition('2026-08-20', 'chittagong'), 1, section: 'চট্টগ্রামের পাতা');

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertSee('চট্টগ্রামের পাতা')
            ->assertDontSee('ঢাকার পাতা');
    }

    public function test_an_edition_that_was_not_published_that_day_is_a_404(): void
    {
        $this->issue('2026-08-20', pages: 1);
        $this->page($this->edition('2026-08-19', 'dhaka'), 1);

        // Published, but not on this date.
        $this->get('/epaper/2026-08-20?edition=dhaka')->assertNotFound();

        // Never published at all.
        $this->get('/epaper/2026-08-20?edition=sylhet')->assertNotFound();
    }

    public function test_an_unpublished_edition_is_not_served_to_a_reader(): void
    {
        $this->page($this->edition('2026-08-20', 'main'), 1, section: 'প্রধান পাতা');
        $this->page($this->edition('2026-08-20', 'dhaka', published: false), 1);

        $this->get('/epaper/2026-08-20?edition=dhaka')->assertNotFound();
    }

    /**
     * The house edition's issues keep the URLs they have always had, so a
     * single-edition paper — every install until somebody creates a second
     * issue — never sees the query parameter at all. Naming it explicitly is
     * the same paper at a second address, so it redirects once.
     */
    public function test_the_house_edition_has_one_canonical_url(): void
    {
        $this->issue('2026-08-20', pages: 1);

        $this->assertSame(url('/epaper/2026-08-20'), Epaper::where('edition', 'main')->first()->url);

        $this->get('/epaper/2026-08-20?edition=main')
            ->assertRedirect(url('/epaper/2026-08-20'))
            ->assertStatus(301);

        $this->get('/epaper?edition=main')
            ->assertRedirect(route('epaper.index'))
            ->assertStatus(301);
    }

    /** A non-house edition carries its name and must not redirect anywhere. */
    public function test_a_named_edition_does_not_redirect(): void
    {
        $this->page($this->edition('2026-08-20', 'dhaka'), 1);

        $this->get('/epaper/2026-08-20?edition=dhaka')->assertOk();
    }

    /** The hub follows the edition asked for, not the newest issue overall. */
    public function test_the_hub_opens_the_newest_issue_of_the_edition_asked_for(): void
    {
        $this->page($this->edition('2026-08-18', 'dhaka'), 1, section: 'ঢাকার পাতা');
        $this->page($this->edition('2026-08-20', 'main'), 1, section: 'প্রধান পাতা');

        $this->get('/epaper?edition=dhaka')
            ->assertOk()
            ->assertSee('১৮ আগস্ট ২০২৬', false)
            ->assertSee('ঢাকার পাতা')
            ->assertDontSee('প্রধান পাতা');
    }

    /**
     * An edition that has never run is the empty state and not a 404: the hub
     * is a place rather than a document, and `/epaper` already answers 200
     * with nothing published at all.
     */
    public function test_the_hub_renders_the_empty_state_for_an_edition_that_has_never_run(): void
    {
        $this->issue('2026-08-20', pages: 1);

        $this->get('/epaper?edition=sylhet')
            ->assertOk()
            ->assertSee('ই-পেপার এখনো প্রকাশিত হয়নি');
    }

    // ── The edition switcher ─────────────────────────────────────────────

    /**
     * Without this the second edition has an address nothing links to, which
     * is the gap only half closed. It carries the configured Bangla label,
     * and marks the one being read.
     */
    public function test_the_switcher_offers_every_edition_of_the_day_being_read(): void
    {
        $this->page($this->edition('2026-08-20', 'main'), 1);
        $this->page($this->edition('2026-08-20', 'dhaka'), 1);

        $switcher = $this->switcher($this->get('/epaper/2026-08-20')->assertOk()->getContent());

        // Asserted as the pill's own text rather than as a substring of the
        // page: an edition *key* appears in its own href, so `assertSee()` on
        // one is green whether or not a label was ever rendered.
        $this->assertSame('প্রধান সংস্করণ', $this->linkText($switcher, url('/epaper/2026-08-20')));
        $this->assertSame('ঢাকা', $this->linkText($switcher, url('/epaper/2026-08-20?edition=dhaka')));

        // The house edition is the one being read, so it is the one marked.
        $this->assertStringContainsString(
            'aria-current', $this->linkTo($switcher, url('/epaper/2026-08-20'))
        );
        $this->assertStringNotContainsString(
            'aria-current', $this->linkTo($switcher, url('/epaper/2026-08-20?edition=dhaka'))
        );
    }

    /** A paper running one edition gets no switcher to choose from one thing. */
    public function test_no_switcher_is_drawn_when_the_day_holds_one_edition(): void
    {
        $this->issue('2026-08-20', pages: 1);

        $this->get('/epaper/2026-08-20')
            ->assertOk()
            ->assertDontSee('aria-label="সংস্করণ"', false);
    }

    /**
     * An edition the config does not label is still a legal row — the admin
     * validates `edition` as any string up to 60 characters — so it falls
     * back to its own key rather than rendering a blank pill.
     */
    public function test_an_unconfigured_edition_falls_back_to_its_own_key(): void
    {
        $this->page($this->edition('2026-08-20', 'main'), 1);
        $this->page($this->edition('2026-08-20', 'barisal'), 1);

        $switcher = $this->switcher($this->get('/epaper/2026-08-20')->assertOk()->getContent());

        // Not `assertSee('barisal')`: the key is in the pill's own href, so
        // that assertion passes with no label rendered at all.
        $this->assertSame(
            'barisal', $this->linkText($switcher, url('/epaper/2026-08-20?edition=barisal'))
        );
    }

    // ── The rail, once there is more than one edition ────────────────────

    /**
     * Unscoped, a paper running two editions gets a rail of duplicated dates
     * whose links all lead to the same issue — which is the old bug wearing a
     * different hat.
     */
    public function test_the_rail_stays_inside_the_edition_being_read(): void
    {
        $this->page($this->edition('2026-08-20', 'dhaka'), 1);
        $this->page($this->edition('2026-08-19', 'dhaka'), 1);
        $this->page($this->edition('2026-08-18', 'main'), 1);

        $html = $this->get('/epaper/2026-08-20?edition=dhaka')->assertOk()->getContent();
        $rail = html_entity_decode($html);

        $this->assertStringContainsString(url('/epaper/2026-08-19?edition=dhaka'), $rail);
        $this->assertStringNotContainsString(url('/epaper/2026-08-18'), $rail);

        // And the dates are not listed twice under one edition.
        $this->assertSame(1, substr_count($rail, url('/epaper/2026-08-19?edition=dhaka')));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function issue(
        string $date,
        int $pages = 0,
        bool $published = true,
        array $attributes = [],
    ): Epaper {
        $epaper = Epaper::create([
            'date' => $date,
            'edition' => 'main',
            'is_published' => $published,
            ...$attributes,
        ]);

        for ($number = 1; $number <= $pages; $number++) {
            $this->page($epaper, $number);
        }

        return $epaper;
    }

    private function page(
        Epaper $epaper,
        int $number,
        ?string $thumbnail = 'default',
        ?string $section = null,
    ): EpaperPage {
        return $epaper->pages()->create([
            'page_number' => $number,
            'image' => "uploads/epaper/p{$number}.jpg",
            'thumbnail' => $thumbnail === 'default' ? "uploads/epaper/p{$number}-thumb.webp" : $thumbnail,
            'section' => $section,
        ]);
    }

    /** One issue of a named edition, created after any earlier call. */
    private function edition(string $date, string $edition, bool $published = true): Epaper
    {
        return $this->issue($date, published: $published, attributes: ['edition' => $edition]);
    }

    /** The rail's anchor for one date, so its classes can be inspected. */
    private function railLink(string $html, string $date): string
    {
        return $this->linkTo($html, route('epaper.show', $date));
    }

    /** The visible text of the anchor pointing at one href. */
    private function linkText(string $html, string $href): string
    {
        return trim(strip_tags($this->linkTo($html, $href)));
    }

    /**
     * The anchor pointing at one href, so its text and attributes can be
     * inspected.
     *
     * Matched as a whole `<a>` element carrying that exact `href="…"`, not as
     * a substring of the page. Both looser forms are wrong here and were:
     * the bare URL matches the `<link rel="canonical">` in the head, and it is
     * also a prefix of every `?edition=` variant of itself.
     */
    private function linkTo(string $html, string $href): string
    {
        preg_match_all('/<a\s[^>]*>.*?<\/a>/s', $html, $anchors);

        foreach ($anchors[0] as $anchor) {
            if (str_contains($anchor, 'href="'.e($href).'"')) {
                return $anchor;
            }
        }

        $this->fail("No link to {$href}.");
    }

    /**
     * The edition switcher alone.
     *
     * The rail links to some of the same URLs, so an assertion made against
     * the whole page can be satisfied by the wrong element.
     */
    private function switcher(string $html): string
    {
        $start = strpos($html, '<nav class="mt-2.5');

        $this->assertNotFalse($start, 'No edition switcher on the page.');

        return substr($html, $start, strpos($html, '</nav>', $start) - $start);
    }
}
