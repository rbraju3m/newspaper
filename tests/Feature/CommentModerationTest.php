<?php

namespace Tests\Feature;

use App\Enums\CommentStatus;
use App\Models\Article;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comment moderation, and the denormalised counter that hangs off it.
 *
 * `articles.comments_count` is maintained by model events rather than a
 * scheduled job, which makes it exactly as reliable as the code path that
 * writes the status — and silently wrong the moment something mass-updates.
 */
class CommentModerationTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->editor()->create()->fresh();
    }

    // ── Who may moderate ─────────────────────────────────────────────────

    public function test_a_reporter_cannot_moderate(): void
    {
        $comment = Comment::factory()->pending()->create();

        $this->actingAs(User::factory()->reporter()->create()->fresh())
            ->patch("/admin/comments/{$comment->id}", ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame(CommentStatus::Pending, $comment->fresh()->status);
    }

    public function test_an_editor_can_approve_a_pending_comment(): void
    {
        $editor = $this->editor();
        $comment = Comment::factory()->pending()->create();

        $this->actingAs($editor)
            ->patch("/admin/comments/{$comment->id}", ['status' => 'approved'])
            ->assertRedirect();

        $comment = $comment->fresh();

        $this->assertSame(CommentStatus::Approved, $comment->status);
        // moderated_by/moderated_at are guarded, so they only get set if the
        // controller went through forceFill rather than a plain update.
        $this->assertSame($editor->id, $comment->moderated_by);
        $this->assertNotNull($comment->moderated_at);
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $comment = Comment::factory()->pending()->create();

        $this->actingAs($this->editor())
            ->patch("/admin/comments/{$comment->id}", ['status' => 'banana'])
            ->assertStatus(422);
    }

    // ── The counter ──────────────────────────────────────────────────────

    public function test_approving_and_unapproving_moves_the_article_counter(): void
    {
        $article = Article::factory()->create();
        $comment = Comment::factory()->pending()->for($article)->create();

        $this->assertSame(0, $article->fresh()->comments_count);

        $this->actingAs($this->editor())
            ->patch("/admin/comments/{$comment->id}", ['status' => 'approved']);
        $this->assertSame(1, $article->fresh()->comments_count);

        // And back down again. The updated hook compares getRawOriginal(),
        // because getOriginal() applies the cast and hands back the enum.
        $this->actingAs($this->editor())
            ->patch("/admin/comments/{$comment->id}", ['status' => 'rejected']);
        $this->assertSame(0, $article->fresh()->comments_count);
    }

    public function test_the_counter_does_not_double_count_a_repeated_approval(): void
    {
        $article = Article::factory()->create();
        $comment = Comment::factory()->pending()->for($article)->create();

        foreach ([1, 2, 3] as $ignored) {
            $this->actingAs($this->editor())
                ->patch("/admin/comments/{$comment->id}", ['status' => 'approved']);
        }

        $this->assertSame(1, $article->fresh()->comments_count);
    }

    public function test_deleting_an_approved_comment_decrements_the_counter(): void
    {
        $article = Article::factory()->create();
        $comment = Comment::factory()->for($article)->create();  // approved

        $this->assertSame(1, $article->fresh()->comments_count);

        $this->actingAs($this->editor())
            ->delete("/admin/comments/{$comment->id}")
            ->assertRedirect();

        $this->assertSame(0, $article->fresh()->comments_count);
    }

    /**
     * The reason the bulk action loops and saves row by row instead of issuing
     * one `whereIn(...)->update()`: a mass update does not fire model events,
     * so the counters would drift every time an editor cleared the queue.
     */
    public function test_bulk_approval_still_fires_the_counter_hooks(): void
    {
        $article = Article::factory()->create();
        $comments = Comment::factory()->pending()->count(3)->for($article)->create();

        $this->actingAs($this->editor())
            ->post('/admin/comments/bulk', [
                'action' => 'approve',
                'ids' => $comments->pluck('id')->all(),
            ])
            ->assertRedirect();

        $this->assertSame(3, $article->fresh()->comments_count);
    }

    public function test_bulk_delete_removes_the_comments(): void
    {
        $article = Article::factory()->create();
        $comments = Comment::factory()->count(2)->for($article)->create();

        $this->actingAs($this->editor())
            ->post('/admin/comments/bulk', [
                'action' => 'delete',
                'ids' => $comments->pluck('id')->all(),
            ])
            ->assertRedirect();

        $this->assertSame(0, $article->fresh()->comments_count);
        $this->assertSame(0, Comment::whereIn('id', $comments->pluck('id'))->count());
    }

    public function test_bulk_rejects_an_unknown_action(): void
    {
        $comment = Comment::factory()->pending()->create();

        $this->actingAs($this->editor())
            ->post('/admin/comments/bulk', ['action' => 'incinerate', 'ids' => [$comment->id]])
            ->assertSessionHasErrors('action');
    }
}
