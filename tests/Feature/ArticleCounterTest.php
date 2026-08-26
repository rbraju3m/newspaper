<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `categories.articles_count` and `users.articles_count`.
 *
 * Both count published, untrashed articles. Until these hooks existed nothing
 * maintained them at runtime — `ContentSeeder` set them once and they drifted
 * from the first publish onwards — so the coverage here is deliberately about
 * transitions rather than about one happy path.
 *
 * The last test is the safety net: whatever the hooks get wrong,
 * `counters:recompute` puts right, and says how far off it was.
 */
class ArticleCounterTest extends TestCase
{
    use RefreshDatabase;

    private function countFor(Category $category): int
    {
        return (int) DB::table('categories')->where('id', $category->id)->value('articles_count');
    }

    private function countForAuthor(User $author): int
    {
        return (int) DB::table('users')->where('id', $author->id)->value('articles_count');
    }

    private function author(): User
    {
        return User::factory()->create(['role' => UserRole::Reporter]);
    }

    public function test_publishing_raises_both_counts(): void
    {
        $category = Category::factory()->create();
        $author = $this->author();

        Article::factory()->create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => ArticleStatus::Published,
        ]);

        $this->assertSame(1, $this->countFor($category));
        $this->assertSame(1, $this->countForAuthor($author));
    }

    public function test_a_draft_counts_for_nothing(): void
    {
        $category = Category::factory()->create();

        Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Draft,
        ]);

        $this->assertSame(0, $this->countFor($category));
    }

    public function test_publishing_a_draft_later_raises_the_count(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Draft,
        ]);

        $article->update(['status' => ArticleStatus::Published]);

        $this->assertSame(1, $this->countFor($category));
    }

    public function test_unpublishing_lowers_it_again(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Published,
        ]);

        $article->update(['status' => ArticleStatus::Draft]);

        $this->assertSame(0, $this->countFor($category));
    }

    /** The count follows the article, off one category and onto the other. */
    public function test_moving_category_moves_the_count(): void
    {
        $from = Category::factory()->create();
        $to = Category::factory()->create();

        $article = Article::factory()->create([
            'category_id' => $from->id,
            'status' => ArticleStatus::Published,
        ]);

        $article->update(['category_id' => $to->id]);

        $this->assertSame(0, $this->countFor($from));
        $this->assertSame(1, $this->countFor($to));
    }

    public function test_reassigning_the_byline_moves_the_count(): void
    {
        $from = $this->author();
        $to = $this->author();

        $article = Article::factory()->create([
            'category_id' => Category::factory()->create()->id,
            'author_id' => $from->id,
            'status' => ArticleStatus::Published,
        ]);

        $article->update(['author_id' => $to->id]);

        $this->assertSame(0, $this->countForAuthor($from));
        $this->assertSame(1, $this->countForAuthor($to));
    }

    /** An edit that touches neither status, category nor byline must not move it. */
    public function test_editing_the_headline_leaves_the_count_alone(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Published,
        ]);

        $article->update(['title' => 'সংশোধিত শিরোনাম']);

        $this->assertSame(1, $this->countFor($category));
    }

    public function test_trashing_lowers_the_count_and_restoring_raises_it(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Published,
        ]);

        $article->delete();
        $this->assertSame(0, $this->countFor($category));

        $article->restore();
        $this->assertSame(1, $this->countFor($category));
    }

    /**
     * A trashed article was already taken off the count. Emptying the bin
     * must not take it off twice — which, on an unsigned column, is an
     * out-of-range error rather than a merely wrong number.
     */
    public function test_force_deleting_a_trashed_article_does_not_double_count(): void
    {
        $category = Category::factory()->create();
        $a = Article::factory()->create(['category_id' => $category->id, 'status' => ArticleStatus::Published]);
        $b = Article::factory()->create(['category_id' => $category->id, 'status' => ArticleStatus::Published]);

        $this->assertSame(2, $this->countFor($category));

        $a->delete();
        $a->forceDelete();

        $this->assertSame(1, $this->countFor($category), 'b is still published');
    }

    /** Force-deleting a live article was never decremented, so it must be now. */
    public function test_force_deleting_a_live_article_lowers_the_count(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Published,
        ]);

        $article->forceDelete();

        $this->assertSame(0, $this->countFor($category));
    }

    /** The column is unsigned: decrementing zero is an error, not a negative. */
    public function test_the_count_never_goes_below_zero(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Published,
        ]);

        DB::table('categories')->where('id', $category->id)->update(['articles_count' => 0]);

        $article->update(['status' => ArticleStatus::Draft]);

        $this->assertSame(0, $this->countFor($category));
    }

    // -----------------------------------------------------------------------
    // counters:recompute — the reconcile
    // -----------------------------------------------------------------------

    public function test_recompute_corrects_drift_that_events_could_not_see(): void
    {
        $category = Category::factory()->create();
        $author = $this->author();

        Article::factory()->count(3)->create([
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => ArticleStatus::Published,
        ]);

        // A bulk update fires no model events — exactly the hole this fills.
        Article::where('category_id', $category->id)->update(['status' => ArticleStatus::Draft]);

        $this->assertSame(3, $this->countFor($category), 'the bulk update bypassed the hooks');

        $this->artisan('counters:recompute')->assertSuccessful();

        $this->assertSame(0, $this->countFor($category));
        $this->assertSame(0, $this->countForAuthor($author));
    }

    public function test_a_dry_run_reports_drift_without_correcting_it(): void
    {
        $category = Category::factory()->create();
        DB::table('categories')->where('id', $category->id)->update(['articles_count' => 42]);

        $this->artisan('counters:recompute --dry-run')->assertSuccessful();

        $this->assertSame(42, $this->countFor($category));
    }

    public function test_recompute_is_idempotent(): void
    {
        $category = Category::factory()->create();
        Article::factory()->count(2)->create([
            'category_id' => $category->id,
            'status' => ArticleStatus::Published,
        ]);

        $this->artisan('counters:recompute')->assertSuccessful();
        $first = $this->countFor($category);

        $this->artisan('counters:recompute')->assertSuccessful();

        $this->assertSame($first, $this->countFor($category));
        $this->assertSame(2, $first);
    }
}
