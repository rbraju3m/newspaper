<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The error pages.
 *
 * Two families with opposite requirements. The 4xx pages run while the
 * application is healthy, so they carry the masthead and the nav and give a
 * reader somewhere to go. The 5xx pages run when it is not, so they must not
 * touch the database, the cache or the asset manifest — a layout that throws
 * while rendering an error page loses the error page, and what the reader gets
 * instead is a blank screen or a stack trace.
 *
 * Every one of them is in Bangla, which is the whole reason they exist: the
 * framework's own are English on a Bangla-first site.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    private function bangla(string $html): bool
    {
        return (bool) preg_match('/[\x{0980}-\x{09FF}]/u', $html);
    }

    // -----------------------------------------------------------------------
    // While the application is healthy
    // -----------------------------------------------------------------------

    public function test_a_missing_page_gets_the_bangla_404(): void
    {
        $response = $this->get('/no-such-page-exists/at-all');

        $response->assertNotFound();
        $response->assertSee('৪০৪', false);
        $response->assertSee('খুঁজে পাওয়া যায়নি', false);
        $this->assertTrue($this->bangla($response->getContent()));
    }

    /**
     * A reader arrived looking for something specific. Offering only the front
     * page throws that away, so the 404 carries a search box.
     */
    public function test_the_404_offers_a_way_to_keep_looking(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertSee(route('search'), false);
        $response->assertSee('name="q"', false);
    }

    /** The chrome is the point of extending the site layout at these codes. */
    public function test_the_404_keeps_the_masthead_and_the_nav(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertSee('ই-পেপার', false);        // header nav
        $response->assertSee('আর্কাইভ', false);        // header nav
        $response->assertSee(config('site.name_bn'), false);   // masthead
    }

    public function test_a_refused_page_gets_the_bangla_403(): void
    {
        // A reporter reaching an admin-only screen.
        $reporter = User::factory()->create(['role' => UserRole::Reporter])->fresh();

        $response = $this->actingAs($reporter)->get(route('admin.settings'));

        $response->assertForbidden();
        $response->assertSee('৪০৩', false);
        $response->assertSee('অনুমতি নেই', false);
    }

    public function test_a_throttled_request_gets_the_bangla_429(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get(route('search', ['q' => 'x']));
        }

        $response = $this->get(route('search', ['q' => 'x']));

        $response->assertStatus(429);
        $response->assertSee('৪২৯', false);
        $response->assertSee('একটু ধীরে', false);
    }

    /** ThrottleRequests puts the wait on the response; the page shows it. */
    public function test_the_429_tells_the_reader_how_long_to_wait(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get(route('search', ['q' => 'x']));
        }

        $this->get(route('search', ['q' => 'x']))->assertSee('সেকেন্ড পর', false);
    }

    // -----------------------------------------------------------------------
    // When it is not healthy
    // -----------------------------------------------------------------------

    /**
     * The assertion this file exists for.
     *
     * `layouts.site` is fed by view composers that query the category tree,
     * and the cache store is the database. At the moment a 500 renders, none
     * of that can be relied on — so these views must not reach for any of it.
     */
    public function test_the_5xx_pages_render_with_no_database(): void
    {
        $port = config('database.connections.mysql.port');

        try {
            config(['database.connections.mysql.port' => 1]);
            DB::purge('mysql');

            foreach (['errors.500', 'errors.503', 'errors.5xx'] as $view) {
                $html = view($view, ['exception' => new HttpException(500, 'boom')])->render();

                $this->assertTrue($this->bangla($html), "{$view} lost its Bangla");
                $this->assertStringContainsString('<style>', $html, "{$view} must carry its own CSS");
            }
        } finally {
            // RefreshDatabase rolls back in tearDown and needs the connection
            // it started with. Leaving it pointing at a dead port fails the
            // *next* test, which is a miserable thing to debug.
            config(['database.connections.mysql.port' => $port]);
            DB::purge('mysql');
        }
    }

    /**
     * `artisan down` pre-renders 503 to a static file served without booting
     * the application, so a build that replaces the hashed bundle between
     * `down` and `up` would leave it pointing at a file that no longer exists.
     */
    public function test_the_5xx_pages_do_not_reference_the_asset_bundle(): void
    {
        foreach (['errors.500', 'errors.503'] as $view) {
            $html = view($view, ['exception' => null])->render();

            $this->assertStringNotContainsString('/build/', $html);
            $this->assertStringNotContainsString('<script', $html);
        }
    }

    public function test_the_maintenance_page_says_so_in_bangla(): void
    {
        $html = view('errors.503', ['exception' => null])->render();

        $this->assertStringContainsString('৫০৩', $html);
        $this->assertStringContainsString('সাময়িকভাবে বন্ধ', $html);
    }

    public function test_the_500_page_gives_nothing_away(): void
    {
        $html = view('errors.500', ['exception' => null])->render();

        $this->assertStringNotContainsString('SQLSTATE', $html);
        $this->assertStringNotContainsString('vendor/laravel', $html);
        $this->assertStringContainsString('কিছু একটা ভুল হয়েছে', $html);
    }

    // -----------------------------------------------------------------------
    // Fallbacks
    // -----------------------------------------------------------------------

    /**
     * Laravel falls back to `4xx`/`5xx` before falling back to its own page.
     *
     * The 4xx one goes through a real request rather than `view()`: it extends
     * the site layout, and flash-toasts wants the ViewErrorBag that only a
     * request shares.
     */
    public function test_the_four_hundreds_fallback_renders(): void
    {
        $response = $this->put('/');   // no PUT route: 405

        $response->assertStatus(405);
        $response->assertSee('৪০৫', false);
        $response->assertSee('সম্পন্ন করা যায়নি', false);
    }

    public function test_the_five_hundreds_fallback_renders(): void
    {
        $html = view('errors.5xx', ['exception' => new HttpException(502, '')])->render();

        $this->assertStringContainsString('৫০২', $html);
    }

    /** An article still 404s for a guest; the page around it just got better. */
    public function test_a_draft_still_hides_behind_the_new_page(): void
    {
        $article = Article::factory()->draft()->create([
            'category_id' => Category::factory()->create()->id,
        ]);

        $this->get($article->url)->assertNotFound()->assertSee('৪০৪', false);
    }
}
