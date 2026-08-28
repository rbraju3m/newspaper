<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Category;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `articles:publish-due` — scheduling that actually publishes.
 *
 * Before this existed, `scheduled` was a label: an article with a future
 * `published_at` waited for somebody to open the admin and change its status.
 * The test that matters here is the last one — not "the column changed", but
 * "a reader can now read it".
 */
class ScheduledPublishingTest extends TestCase
{
    use RefreshDatabase;

    private function scheduled(string $when, array $overrides = []): Article
    {
        return Article::factory()->create(array_merge([
            'category_id' => Category::factory()->create()->id,
            'status' => ArticleStatus::Scheduled,
            'published_at' => now()->parse($when),
        ], $overrides));
    }

    public function test_it_publishes_an_article_whose_time_has_come(): void
    {
        $article = $this->scheduled('-5 minutes');

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertSame(ArticleStatus::Published, $article->fresh()->status);
    }

    public function test_it_leaves_a_future_article_scheduled(): void
    {
        $article = $this->scheduled('+2 hours');

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertSame(ArticleStatus::Scheduled, $article->fresh()->status);
    }

    /** The editor chose that time. Cron arriving late must not rewrite it. */
    public function test_it_keeps_the_time_the_editor_chose(): void
    {
        $article = $this->scheduled('-37 minutes');
        $chosen = $article->published_at;

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertTrue($chosen->equalTo($article->fresh()->published_at));
    }

    public function test_it_does_not_touch_drafts_or_articles_in_review(): void
    {
        $draft = $this->scheduled('-1 hour', ['status' => ArticleStatus::Draft]);
        $review = $this->scheduled('-1 hour', ['status' => ArticleStatus::Review]);

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertSame(ArticleStatus::Draft, $draft->fresh()->status);
        $this->assertSame(ArticleStatus::Review, $review->fresh()->status);
    }

    /**
     * A scheduled row with no date can never be "due". Publishing it because
     * NULL sorts oddly would put an unfinished story on the front page.
     */
    public function test_a_scheduled_article_with_no_date_is_left_alone(): void
    {
        $article = $this->scheduled('-1 hour');
        $article->forceFill(['published_at' => null])->save();

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertSame(ArticleStatus::Scheduled, $article->fresh()->status);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $article = $this->scheduled('-5 minutes');

        $this->artisan('articles:publish-due --dry-run')->assertSuccessful();

        $this->assertSame(ArticleStatus::Scheduled, $article->fresh()->status);
    }

    /** The front page is assembled from cached blocks. */
    public function test_it_flushes_the_homepage_cache(): void
    {
        $this->scheduled('-5 minutes');

        (new HomepageService)->build();
        $this->assertNotNull(Cache::get(HomepageService::CACHE_KEY));

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertNull(Cache::get(HomepageService::CACHE_KEY));
    }

    /** Nothing due must not cost a cache flush on every one of 1,440 runs. */
    public function test_it_leaves_the_cache_alone_when_nothing_is_due(): void
    {
        $this->scheduled('+2 hours');

        (new HomepageService)->build();

        $this->artisan('articles:publish-due')->assertSuccessful();

        $this->assertNotNull(Cache::get(HomepageService::CACHE_KEY));
    }

    /**
     * The assertion that matters: not that a column changed, but that the
     * story is readable. A scheduled article 404s for a guest beforehand,
     * because `scopePublished` requires both the status and the date.
     */
    public function test_the_article_becomes_readable_by_the_public(): void
    {
        $article = $this->scheduled('-5 minutes');

        $this->get($article->url)->assertNotFound();

        $this->artisan('articles:publish-due')->assertSuccessful();

        // `url` reads `category`, so the refetched row needs it loaded.
        $this->get($article->fresh('category')->url)
            ->assertOk()
            ->assertSee($article->title, false);
    }
}
