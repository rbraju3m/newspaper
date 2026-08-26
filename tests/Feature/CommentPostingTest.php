<?php

namespace Tests\Feature;

use App\Enums\CommentStatus;
use App\Models\Article;
use App\Models\Comment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Posting, editing and reacting to comments as a reader.
 *
 * Moderation from the desk's side is CommentModerationTest; this is the public
 * half — who may post at all, what state a new comment lands in, and the two
 * abuse controls (a per-reader rate limit and report-driven auto-hide).
 */
class CommentPostingTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->article = Article::factory()->create(['published_at' => now()->subHour()]);
        Setting::flush();
    }

    private function verifiedReader(): User
    {
        return User::factory()->create()->fresh();
    }

    private function body(string $text = 'এটি একটি যথেষ্ট দীর্ঘ মন্তব্য।'): array
    {
        return ['body' => $text];
    }

    // ── Who may post ─────────────────────────────────────────────────────

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->post("/articles/{$this->article->id}/comments", $this->body())
            ->assertRedirect('/login');

        $this->assertSame(0, Comment::count());
    }

    public function test_an_unverified_reader_cannot_post(): void
    {
        // The single most effective spam control here, and cheaper than any
        // filter — so it is worth a test that it has not been loosened.
        $reader = User::factory()->unverified()->create()->fresh();

        $this->actingAs($reader)
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", $this->body())
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Comment::count());
    }

    public function test_a_verified_reader_can_post_and_it_lands_pending(): void
    {
        $reader = $this->verifiedReader();

        $this->actingAs($reader)
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", $this->body())
            ->assertSessionHasNoErrors();

        $comment = Comment::firstOrFail();

        $this->assertSame($reader->id, $comment->user_id);
        $this->assertSame($this->article->id, $comment->article_id);
        $this->assertSame(CommentStatus::Pending, $comment->status);
        // Pending, so it must not yet count towards the article's total.
        $this->assertSame(0, $this->article->fresh()->comments_count);
    }

    public function test_with_approval_switched_off_a_comment_publishes_immediately(): void
    {
        Setting::put('comments_require_approval', false, 'boolean');
        Setting::flush();

        $this->actingAs($this->verifiedReader())
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", $this->body());

        $this->assertSame(CommentStatus::Approved, Comment::firstOrFail()->status);
        $this->assertSame(1, $this->article->fresh()->comments_count);
    }

    public function test_comments_can_be_closed_on_a_single_story(): void
    {
        $closed = Article::factory()->create([
            'published_at' => now()->subHour(),
            'allow_comments' => false,
        ]);

        $this->actingAs($this->verifiedReader())
            ->from($closed->url)
            ->post("/articles/{$closed->id}/comments", $this->body())
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Comment::count());
    }

    public function test_an_unpublished_story_accepts_no_comments(): void
    {
        $draft = Article::factory()->draft()->create();

        $this->actingAs($this->verifiedReader())
            ->from('/')
            ->post("/articles/{$draft->id}/comments", $this->body())
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Comment::count());
    }

    public function test_a_too_short_comment_is_rejected(): void
    {
        $this->actingAs($this->verifiedReader())
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", ['body' => 'ছোট'])
            ->assertSessionHasErrors('body');
    }

    public function test_posting_is_rate_limited_per_reader(): void
    {
        $reader = $this->verifiedReader();

        foreach (range(1, 5) as $i) {
            $this->actingAs($reader)
                ->from($this->article->url)
                ->post("/articles/{$this->article->id}/comments", $this->body("মন্তব্য সংখ্যা {$i} এখানে।"));
        }

        $this->assertSame(5, Comment::count());

        $this->actingAs($reader)
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", $this->body('ছয় নম্বর মন্তব্যটি এখানে।'))
            ->assertSessionHasErrors('body');

        $this->assertSame(5, Comment::count());
    }

    // ── Replies ──────────────────────────────────────────────────────────

    public function test_a_reply_cannot_be_grafted_onto_another_articles_thread(): void
    {
        $elsewhere = Comment::factory()->create();   // belongs to a different article

        $this->actingAs($this->verifiedReader())
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", $this->body() + ['parent_id' => $elsewhere->id])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_threads_are_flattened_to_one_level(): void
    {
        // Deeper nesting is unreadable on a phone, which is most of this
        // traffic, so a reply to a reply re-parents to the top comment.
        $reader = $this->verifiedReader();

        $top = Comment::factory()->for($this->article)->create();
        $reply = Comment::factory()->for($this->article)->create(['parent_id' => $top->id]);

        $this->actingAs($reader)
            ->from($this->article->url)
            ->post("/articles/{$this->article->id}/comments", $this->body('উত্তরের উত্তর দিচ্ছি।') + ['parent_id' => $reply->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($top->id, Comment::latest('id')->first()->parent_id);
    }

    // ── Editing ──────────────────────────────────────────────────────────

    public function test_an_edit_sends_the_comment_back_to_the_queue(): void
    {
        // Otherwise an approved comment could be rewritten into anything.
        $reader = $this->verifiedReader();
        $comment = Comment::factory()->for($this->article)->create(['user_id' => $reader->id]);

        $this->assertSame(1, $this->article->fresh()->comments_count);

        $this->actingAs($reader)
            ->from($this->article->url)
            ->patch("/comments/{$comment->id}", ['body' => 'সম্পাদিত মন্তব্যের নতুন লেখা।'])
            ->assertSessionHasNoErrors();

        $this->assertSame(CommentStatus::Pending, $comment->fresh()->status);
        $this->assertSame(0, $this->article->fresh()->comments_count);
    }

    public function test_a_reader_cannot_edit_somebody_elses_comment(): void
    {
        $comment = Comment::factory()->for($this->article)->create();

        $this->actingAs($this->verifiedReader())
            ->patch("/comments/{$comment->id}", ['body' => 'অন্যের মন্তব্য বদলে দিচ্ছি।'])
            ->assertForbidden();
    }

    public function test_the_edit_window_closes_after_fifteen_minutes(): void
    {
        $reader = $this->verifiedReader();
        $comment = Comment::factory()->for($this->article)->create([
            'user_id' => $reader->id,
            'created_at' => now()->subMinutes(20),
        ]);

        $this->actingAs($reader)
            ->patch("/comments/{$comment->id}", ['body' => 'অনেক পরে সম্পাদনা করছি।'])
            ->assertForbidden();
    }

    public function test_a_reader_can_delete_their_own_comment(): void
    {
        $reader = $this->verifiedReader();
        $comment = Comment::factory()->for($this->article)->create(['user_id' => $reader->id]);

        $this->actingAs($reader)
            ->from($this->article->url)
            ->delete("/comments/{$comment->id}")
            ->assertRedirect($this->article->url);

        $this->assertSame(0, $this->article->fresh()->comments_count);
    }

    // ── Likes and reports ────────────────────────────────────────────────

    public function test_liking_toggles_and_keeps_the_counter_in_step(): void
    {
        $reader = $this->verifiedReader();
        $comment = Comment::factory()->for($this->article)->create();

        $this->actingAs($reader)
            ->postJson("/comments/{$comment->id}/like")
            ->assertOk()
            ->assertJson(['liked' => true, 'count' => 1]);

        $this->actingAs($reader)
            ->postJson("/comments/{$comment->id}/like")
            ->assertOk()
            ->assertJson(['liked' => false, 'count' => 0]);
    }

    public function test_the_same_reader_cannot_report_twice(): void
    {
        $reader = $this->verifiedReader();
        $comment = Comment::factory()->for($this->article)->create();

        $this->actingAs($reader)->postJson("/comments/{$comment->id}/report")->assertOk();
        $this->actingAs($reader)->postJson("/comments/{$comment->id}/report")->assertOk();

        $this->assertSame(1, $comment->fresh()->reports_count);
    }

    public function test_five_reports_auto_hide_an_approved_comment(): void
    {
        // A brigading attack must not be able to keep abusive content live
        // while it waits in the moderation queue.
        $comment = Comment::factory()->for($this->article)->create();

        foreach (range(1, 5) as $ignored) {
            // A fresh reader *and* a fresh session each time. The dedupe is
            // held in the session rather than against the user, so without the
            // flush all five reports share one session and four are swallowed —
            // five different readers in one browser would be deduped as one.
            $this->flushSession();

            $this->actingAs($this->verifiedReader())
                ->postJson("/comments/{$comment->id}/report")
                ->assertOk();
        }

        $this->assertSame(5, $comment->fresh()->reports_count);
        $this->assertSame(CommentStatus::Pending, $comment->fresh()->status);
    }
}
