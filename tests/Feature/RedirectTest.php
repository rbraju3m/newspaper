<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Old-CMS URL preservation — the `redirects` table, and the two halves that
 * now use it.
 *
 * The table shipped with the schema and nothing read it. What matters most
 * here is not that a redirect redirects, but *when* the lookup happens: it
 * hangs off the 404, so a request that resolves never pays for it and a live
 * page always wins over a rule recorded for the same path.
 *
 * That ordering is the whole design and it is asserted first.
 */
class RedirectTest extends TestCase
{
    use RefreshDatabase;

    // ── When the lookup runs ─────────────────────────────────────────────

    /**
     * A page that exists is served, not redirected — a rule cannot shadow real
     * content. `/{category}` is constrained `.*`, so this path resolves
     * through the catch-all and never reaches the 404 the resolver hangs off.
     */
    public function test_a_live_page_wins_over_a_rule_recorded_for_the_same_path(): void
    {
        $category = Category::factory()->create(['slug' => 'khela', 'path' => 'khela']);

        Redirect::create(['from' => 'khela', 'to' => 'sports']);

        $this->get('/khela')->assertOk();

        $this->assertSame(0, (int) DB::table('redirects')->value('hits'));
        $this->assertNotNull($category->fresh());
    }

    /** The cost of the feature on a request that resolves is zero queries. */
    public function test_a_resolving_request_never_queries_the_table(): void
    {
        Category::factory()->create(['slug' => 'khela', 'path' => 'khela']);
        Redirect::create(['from' => 'khela', 'to' => 'sports']);

        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            if (str_contains($q->sql, 'redirects')) {
                $queries[] = $q->sql;
            }
        });

        $this->get('/khela')->assertOk();

        $this->assertSame([], $queries, 'A resolving request read the redirects table.');
    }

    // ── The redirect itself ──────────────────────────────────────────────

    public function test_a_recorded_path_redirects_permanently(): void
    {
        Redirect::create(['from' => 'old-section', 'to' => 'khela']);

        $this->get('/old-section')
            ->assertRedirect(url('/khela'))
            ->assertStatus(301);
    }

    /**
     * The catch-all swallows every depth, so a legacy URL like this one 404s
     * from inside `CategoryController` rather than from the router. One hook
     * has to cover both, and `prepareException()` is what makes it so.
     */
    public function test_a_deep_legacy_path_redirects(): void
    {
        Redirect::create(['from' => '2019/05/old-story.html', 'to' => 'khela/12/notun']);

        $this->get('/2019/05/old-story.html')
            ->assertRedirect(url('/khela/12/notun'))
            ->assertStatus(301);
    }

    /** A `firstOrFail()` 404 is a `ModelNotFoundException` until the handler prepares it. */
    public function test_a_model_not_found_404_redirects_too(): void
    {
        Redirect::create(['from' => 'epaper/2026-08-20', 'to' => 'epaper']);

        // No issue on that date, so `Site\EpaperController::show()` throws
        // ModelNotFoundException rather than NotFoundHttpException.
        $this->get('/epaper/2026-08-20')
            ->assertRedirect(url('/epaper'))
            ->assertStatus(301);
    }

    public function test_a_path_with_no_rule_is_still_a_404(): void
    {
        Redirect::create(['from' => 'old-section', 'to' => 'khela']);

        $this->get('/something-else')->assertNotFound();
    }

    public function test_the_status_column_is_honoured(): void
    {
        Redirect::create(['from' => 'moved-for-now', 'to' => 'khela', 'status' => 302]);

        $this->get('/moved-for-now')->assertStatus(302);
    }

    /**
     * `status` is an operator-supplied smallint with nothing constraining it,
     * and `redirect()->to($url, 500)` is not a redirect. A typo must not turn
     * a lost page into a broken response.
     */
    public function test_a_status_that_is_not_a_redirect_falls_back_to_301(): void
    {
        Redirect::create(['from' => 'typo', 'to' => 'khela', 'status' => 500]);

        $this->get('/typo')
            ->assertRedirect(url('/khela'))
            ->assertStatus(301);
    }

    public function test_an_absolute_destination_is_used_as_given(): void
    {
        Redirect::create(['from' => 'gone-elsewhere', 'to' => 'https://example.org/story']);

        $this->get('/gone-elsewhere')->assertRedirect('https://example.org/story');
    }

    // ── How a path is matched ────────────────────────────────────────────

    /** However somebody stored it, and whatever they typed, it matches. */
    public function test_a_rule_matches_with_or_without_a_leading_slash(): void
    {
        Redirect::create(['from' => '/with-slash', 'to' => 'khela']);
        Redirect::create(['from' => 'without-slash', 'to' => 'khela']);

        $this->get('/with-slash')->assertRedirect(url('/khela'));
        $this->get('/without-slash')->assertRedirect(url('/khela'));
    }

    /**
     * `redirects.from` is utf8mb4_unicode_ci, so the match is
     * case-insensitive. That is right for legacy URLs — the old CMS's casing
     * is rarely what ends up in the mapping file — but it is a property of the
     * collation rather than of any code, so it is pinned here.
     */
    public function test_matching_is_case_insensitive(): void
    {
        Redirect::create(['from' => 'Old-Section', 'to' => 'khela']);

        $this->get('/old-section')->assertRedirect(url('/khela'));
    }

    /**
     * A legacy CMS often keys on the query string: `/index.php?id=4021` is one
     * URL, not a page called `index.php`.
     */
    public function test_a_rule_may_match_on_the_query_string(): void
    {
        Redirect::create(['from' => 'index.php?id=4021', 'to' => 'khela/12/notun']);

        $this->get('/index.php?id=4021')->assertRedirect(url('/khela/12/notun'));
    }

    /** The query-string rule is the more specific one and beats the bare path. */
    public function test_a_query_string_rule_beats_a_bare_path_rule(): void
    {
        Redirect::create(['from' => 'index.php', 'to' => 'khela']);
        Redirect::create(['from' => 'index.php?id=4021', 'to' => 'khela/12/notun']);

        $this->get('/index.php?id=4021')->assertRedirect(url('/khela/12/notun'));
        $this->get('/index.php')->assertRedirect(url('/khela'));
    }

    /** `?page=3` on a section that moved should survive the move. */
    public function test_an_unmatched_query_string_is_carried_to_the_destination(): void
    {
        Redirect::create(['from' => 'old-section', 'to' => 'khela']);

        $this->get('/old-section?page=3')->assertRedirect(url('/khela').'?page=3');
    }

    /** But not over a destination that brought its own. */
    public function test_a_destination_with_its_own_query_string_keeps_it(): void
    {
        Redirect::create(['from' => 'old-section', 'to' => 'search?q=khela']);

        $this->get('/old-section?page=3')->assertRedirect(url('/search?q=khela'));
    }

    // ── What must not happen ─────────────────────────────────────────────

    /**
     * A rule pointing at its own URL is a browser redirect loop, and the typo
     * that produces one is ordinary. It has to fall through to the 404.
     */
    public function test_a_rule_pointing_at_itself_does_not_loop(): void
    {
        Redirect::create(['from' => 'itself', 'to' => 'itself']);

        $this->get('/itself')->assertNotFound();
    }

    public function test_a_rule_pointing_at_its_own_slashed_form_does_not_loop(): void
    {
        Redirect::create(['from' => 'itself', 'to' => '/itself']);

        $this->get('/itself')->assertNotFound();
    }

    /**
     * A 301 on a POST is re-issued by the browser as a GET with no body, which
     * is a worse outcome than the 404 it replaced.
     *
     * Reached through a route-model binding rather than through an unrouted
     * path: the catch-alls are GET-only, so posting to one is a 405 and never
     * gets as far as the resolver. A bound model that does not exist is how a
     * POST genuinely 404s here.
     */
    public function test_a_post_that_404s_is_not_redirected(): void
    {
        Redirect::create(['from' => 'polls/9999/vote', 'to' => 'khela']);

        $this->post('/polls/9999/vote', ['option_id' => 1])->assertNotFound();

        $this->assertSame(0, (int) DB::table('redirects')->value('hits'));
    }

    /**
     * And the unrouted case, for the record: the catch-alls are registered
     * GET-only, so a POST to a legacy path is a 405 before any of this runs.
     */
    public function test_a_post_to_an_unrouted_legacy_path_is_a_405(): void
    {
        Redirect::create(['from' => 'old-section', 'to' => 'khela']);

        $this->post('/old-section')->assertStatus(405);
    }

    /** Admin and API 404s are not legacy URLs and answer as themselves. */
    public function test_admin_and_api_404s_are_not_redirected(): void
    {
        Redirect::create(['from' => 'admin/old-screen', 'to' => 'khela']);
        Redirect::create(['from' => 'api/old-endpoint', 'to' => 'khela']);

        $this->actingAs($this->admin())->get('/admin/old-screen')->assertNotFound();
        $this->get('/api/old-endpoint')->assertNotFound();
    }

    // ── Hit counting ─────────────────────────────────────────────────────

    /**
     * `hits` is what tells an operator which rules are still carrying traffic
     * a year after the migration, and which can go.
     */
    public function test_a_hit_is_counted_without_touching_updated_at(): void
    {
        $rule = Redirect::create(['from' => 'old-section', 'to' => 'khela']);
        $updatedAt = $rule->updated_at;

        $this->get('/old-section')->assertRedirect(url('/khela'));
        $this->get('/old-section')->assertRedirect(url('/khela'));

        $row = DB::table('redirects')->where('id', $rule->id)->first();

        $this->assertSame(2, (int) $row->hits);
        $this->assertSame(
            $updatedAt->toDateTimeString(),
            $row->updated_at,
            'A hit bumped updated_at, which should mean when the rule was last edited.',
        );
    }

    public function test_a_rule_that_does_not_fire_counts_nothing(): void
    {
        $rule = Redirect::create(['from' => 'itself', 'to' => 'itself']);

        $this->get('/itself')->assertNotFound();

        $this->assertSame(0, (int) DB::table('redirects')->where('id', $rule->id)->value('hits'));
    }

    // ── redirects:import ─────────────────────────────────────────────────

    public function test_the_importer_loads_a_mapping_file(): void
    {
        $this->artisan('redirects:import', ['file' => $this->csv(
            "from,to,status\n/old-one/,khela,301\nold-two,khela/12/notun,302\n"
        )])->assertSuccessful();

        // Normalised on the way in: one stored form, no leading or trailing slash.
        $this->assertDatabaseHas('redirects', ['from' => 'old-one', 'to' => 'khela', 'status' => 301]);
        $this->assertDatabaseHas('redirects', ['from' => 'old-two', 'status' => 302]);

        $this->get('/old-one')->assertRedirect(url('/khela'));
    }

    public function test_the_importer_works_without_a_status_column(): void
    {
        $this->artisan('redirects:import', ['file' => $this->csv("from,to\nold-one,khela\n")])
            ->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from' => 'old-one', 'status' => 301]);
    }

    /** Re-importing corrects the destination without discarding the hit count. */
    public function test_reimporting_updates_the_destination_and_keeps_hits(): void
    {
        $rule = Redirect::create(['from' => 'old-one', 'to' => 'wrong']);
        DB::table('redirects')->where('id', $rule->id)->update(['hits' => 47]);

        $this->artisan('redirects:import', ['file' => $this->csv("from,to\nold-one,khela\n")])
            ->assertSuccessful();

        $row = DB::table('redirects')->where('id', $rule->id)->first();

        $this->assertSame('khela', $row->to);
        $this->assertSame(47, (int) $row->hits);
        $this->assertSame(1, DB::table('redirects')->count());
    }

    public function test_the_importer_skips_self_referencing_and_blank_rows(): void
    {
        $this->artisan('redirects:import', ['file' => $this->csv(
            "from,to\nitself,/itself/\n,khela\nold-one,\ngood,khela\n"
        )])->assertSuccessful();

        $this->assertSame(1, DB::table('redirects')->count());
        $this->assertDatabaseHas('redirects', ['from' => 'good']);
    }

    public function test_the_importer_rewrites_a_status_that_is_not_a_redirect(): void
    {
        $this->artisan('redirects:import', ['file' => $this->csv("from,to,status\nold-one,khela,500\n")])
            ->assertSuccessful();

        $this->assertDatabaseHas('redirects', ['from' => 'old-one', 'status' => 301]);
    }

    /** A file listing one path twice must not fail on its own duplicate. */
    public function test_the_importer_takes_the_last_of_a_repeated_path(): void
    {
        $this->artisan('redirects:import', ['file' => $this->csv(
            "from,to\nold-one,first\nold-one,second\n"
        )])->assertSuccessful();

        $this->assertSame(1, DB::table('redirects')->count());
        $this->assertDatabaseHas('redirects', ['from' => 'old-one', 'to' => 'second']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('redirects:import', [
            'file' => $this->csv("from,to\nold-one,khela\n"),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('redirects')->count());
    }

    public function test_the_importer_refuses_a_file_it_cannot_read(): void
    {
        $this->artisan('redirects:import', ['file' => '/nonexistent/mapping.csv'])->assertFailed();
    }

    public function test_the_importer_refuses_a_file_with_no_from_column(): void
    {
        $this->artisan('redirects:import', ['file' => $this->csv("old,new\na,b\n")])->assertFailed();

        $this->assertSame(0, DB::table('redirects')->count());
    }

    // ── Rules that will never fire ───────────────────────────────────────

    /**
     * The failure this whole check exists for, and it is the common one.
     *
     * `/{category}/{id}/{slug}` takes any numeric middle segment, so a
     * WordPress-style dated permalink matches `article.show` with article id
     * 5. The page never 404s, so the rule never runs — and the reader is
     * canonicalised to a story that has nothing to do with the one they asked
     * for. Nothing anywhere says why.
     *
     * A property of this site's URL scheme, not something the redirects table
     * can fix. Pinned so it stays known.
     */
    public function test_a_dated_permalink_is_captured_by_a_real_article_and_the_rule_never_fires(): void
    {
        $article = Article::factory()->create();

        $rule = Redirect::create([
            'from' => "2019/05/old-story.html",
            'to' => 'khela',
        ]);

        // Route it at the article that exists, the way `/2019/05/...` does.
        $shadowed = Redirect::create([
            'from' => "2019/{$article->id}/old-story.html",
            'to' => 'khela',
        ]);

        $this->get("/2019/{$article->id}/old-story.html")
            ->assertRedirect($article->url);

        $this->assertSame(0, (int) DB::table('redirects')->where('id', $shadowed->id)->value('hits'));

        // The one with no live content behind it still works.
        $this->get('/2019/05/old-story.html')->assertRedirect(url('/khela'));
        $this->assertSame(1, (int) DB::table('redirects')->where('id', $rule->id)->value('hits'));
    }

    /**
     * So the importer says so, while somebody still has the mapping file open.
     *
     * Asserted against the command's captured output rather than by chaining
     * `expectsOutputToContain()`. Each of those registers a Mockery
     * expectation on `doWrite`, and Mockery routes one written line to the
     * *first* matching expectation only — so two substrings that land in the
     * same line never both clear, and the second fails against output that
     * plainly contains it.
     */
    public function test_the_importer_warns_about_a_rule_an_article_already_answers(): void
    {
        $article = Article::factory()->create();

        $output = $this->import("from,to\n2019/{$article->id}/old-story.html,khela\n");

        $this->assertStringContainsString('will never fire', $output);
        $this->assertStringContainsString("article {$article->id}", $output);
    }

    public function test_the_importer_warns_about_a_rule_a_category_already_answers(): void
    {
        Category::factory()->create(['slug' => 'khela', 'path' => 'khela']);

        $output = $this->import("from,to\nkhela,sports\n");

        $this->assertStringContainsString('will never fire', $output);
        $this->assertStringContainsString('the category `khela` still exists', $output);
    }

    /** And stays quiet when there is nothing in the way. */
    public function test_the_importer_does_not_warn_about_a_rule_that_will_fire(): void
    {
        $this->assertStringNotContainsString(
            'will never fire', $this->import("from,to\nold-one,khela\n")
        );
    }

    /**
     * The over-warning case, which the plain one above cannot see: `old-one`
     * is a single segment and routes to `category.show`, so it never reaches
     * the article branch at all. This path does — it is shaped exactly like
     * the dated permalink that *is* shadowed — and must stay quiet, because
     * no article 999999 exists to capture it.
     */
    public function test_the_importer_does_not_warn_when_the_article_it_routes_to_does_not_exist(): void
    {
        $this->assertStringNotContainsString(
            'will never fire', $this->import("from,to\n2019/999999/old-story.html,khela\n")
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * ->fresh(): a factory-built model holds only the attributes the factory
     * set, and strict mode throws on anything else the admin layout reads.
     */
    private function admin(): User
    {
        return User::factory()->admin()->create()->fresh();
    }

    /** Runs the importer over a CSV and returns everything it printed. */
    private function import(string $contents): string
    {
        Artisan::call('redirects:import', ['file' => $this->csv($contents)]);

        return Artisan::output();
    }

    private function csv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'redirects').'.csv';
        file_put_contents($path, $contents);

        return $path;
    }
}
