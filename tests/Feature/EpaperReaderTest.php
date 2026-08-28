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
 * `/epaper` is not its own render path: `index()` resolves the newest
 * published date and delegates to `show()`. Anything asserted about one holds
 * for the other, which is why the two are only distinguished where the
 * resolution itself is the thing under test.
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

    /**
     * Two editions on one day are legal — the admin's unique key is
     * `(date, edition)`, not `date` — but the reader's URL carries only a
     * date. Which edition it lands on is unspecified and the second is
     * unreachable; what must not happen is a 500 or a page holding both.
     *
     * This pins the shape rather than the choice. It is the test that should
     * fail the day somebody adds `?edition=`.
     */
    public function test_a_second_edition_on_the_same_day_is_not_reachable_by_date(): void
    {
        $main = $this->issue('2026-08-20', attributes: ['edition' => 'main']);
        $this->page($main, 1, section: 'প্রধান সংস্করণ');

        $dhaka = $this->issue('2026-08-20', attributes: ['edition' => 'dhaka']);
        $this->page($dhaka, 1, section: 'ঢাকা সংস্করণ');

        $html = $this->get('/epaper/2026-08-20')->assertOk()->getContent();

        $shown = array_filter(
            ['প্রধান সংস্করণ', 'ঢাকা সংস্করণ'],
            fn (string $label) => str_contains($html, $label),
        );

        $this->assertCount(1, $shown, 'The reader served both editions on one page.');
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

    /** The rail's anchor for one date, so its classes can be inspected. */
    private function railLink(string $html, string $date): string
    {
        $needle = route('epaper.show', $date);

        $this->assertStringContainsString($needle, $html, "No rail link for {$date}.");

        $start = strrpos(substr($html, 0, strpos($html, $needle)), '<a ');

        return substr($html, $start, strpos($html, '</a>', $start) - $start);
    }
}
