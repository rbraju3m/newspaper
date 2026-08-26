<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Bangla full-text search.
 *
 * This is the reason the whole suite runs on MySQL rather than SQLite in
 * memory: `Article::search()` silently degrades to a `LIKE` scan on any other
 * driver, so a passing search test on SQLite would prove nothing about the
 * `MATCH ... AGAINST` path production actually uses.
 */
class SearchTest extends TestCase
{
    /**
     * DatabaseTruncation, not RefreshDatabase, and that is not a style choice.
     *
     * InnoDB updates a FULLTEXT index at COMMIT. RefreshDatabase runs each test
     * inside a transaction it rolls back, so fixtures created in the test are
     * never committed and `MATCH ... AGAINST` cannot see them — the row is
     * findable by LIKE and invisible to full-text in the same breath. Every
     * assertion here would then pass or fail for the wrong reason: the
     * relevance tests would return nothing, and `assertDontSee` would go green
     * against an empty result set.
     *
     * Truncating between tests commits, at the cost of being slower.
     */
    use DatabaseTruncation;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create(['name' => 'khela', 'slug' => 'khela']);
    }

    /**
     * Fixtures with a corpus this test controls completely.
     *
     * The factory's generated body is not inert here: BanglaContent injects a
     * phrase from a ten-item pool as an <h2>, and one of them is
     * 'জলবায়ু পরিবর্তনের প্রভাব মোকাবিলায়'. Since the full-text index covers
     * body as well as title, roughly one in ten articles matched a search for
     * জলবায়ু by accident — an assertDontSee that failed on about a tenth of
     * runs and passed on the rest. Pinning excerpt and body to text with no
     * search terms in it is what makes these assertions mean anything.
     */
    private function article(string $title, array $attributes = []): Article
    {
        return Article::factory()->for($this->category)->create([
            'title' => $title,
            'excerpt' => 'সংক্ষিপ্ত বিবরণ।',
            'body' => '<p>নির্দিষ্ট অনুচ্ছেদ।</p>',
            'published_at' => now()->subHour(),
        ] + $attributes);
    }

    public function test_it_finds_an_article_by_a_bangla_word_in_the_title(): void
    {
        $match = $this->article('ঢাকায় জলবায়ু সম্মেলন শুরু হয়েছে');
        $miss = $this->article('চট্টগ্রামে নতুন সেতু উদ্বোধন');

        $this->get('/search?q=জলবায়ু')
            ->assertOk()
            ->assertSee($match->title)
            ->assertDontSee($miss->title);
    }

    public function test_a_short_term_falls_back_to_like(): void
    {
        // Under three characters MySQL's default token size would return
        // nothing, so the scope deliberately switches to LIKE.
        $match = $this->article('ঢাকা বিশ্ববিদ্যালয়ে সমাবর্তন');

        $this->get('/search?q=ঢা')->assertOk()->assertSee($match->title);
    }

    public function test_an_empty_search_renders_the_form_without_results(): void
    {
        $this->article('কোনো একটি শিরোনাম');

        $this->get('/search')->assertOk();
        $this->get('/search?q=')->assertOk();
    }

    public function test_results_can_be_filtered_by_category(): void
    {
        $other = Category::factory()->create(['name' => 'bnodon', 'slug' => 'bnodon']);

        $inKhela = $this->article('জলবায়ু সম্মেলনে ক্রীড়াঙ্গনের অংশগ্রহণ');
        $inOther = Article::factory()->for($other)->create([
            'title' => 'জলবায়ু নিয়ে নতুন চলচ্চিত্র',
            'excerpt' => 'সংক্ষিপ্ত বিবরণ।',
            'body' => '<p>নির্দিষ্ট অনুচ্ছেদ।</p>',
            'published_at' => now()->subHour(),
        ]);

        $this->get('/search?q=জলবায়ু&category=khela')
            ->assertOk()
            ->assertSee($inKhela->title)
            ->assertDontSee($inOther->title);
    }

    public function test_unpublished_articles_never_appear_in_results(): void
    {
        $draft = Article::factory()->draft()->for($this->category)->create([
            'title' => 'জলবায়ু বিষয়ক অপ্রকাশিত খসড়া',
            'excerpt' => 'সংক্ষিপ্ত বিবরণ।',
            'body' => '<p>নির্দিষ্ট অনুচ্ছেদ।</p>',
        ]);

        // The control. Without it an assertDontSee goes green against an empty
        // result set, which is exactly how a broken search looks.
        $published = $this->article('জলবায়ু সম্মেলন নিয়ে প্রকাশিত প্রতিবেদন');

        $this->get('/search?q=জলবায়ু')
            ->assertOk()
            ->assertSee($published->title)
            ->assertDontSee($draft->title);
    }

    public function test_an_unknown_category_filter_is_rejected(): void
    {
        $this->get('/search?q=জলবায়ু&category=no-such-section')
            ->assertSessionHasErrors('category');
    }

    public function test_a_reversed_date_range_is_rejected(): void
    {
        $this->get('/search?q=জলবায়ু&from=2026-08-20&to=2026-08-01')
            ->assertSessionHasErrors('to');
    }
}
