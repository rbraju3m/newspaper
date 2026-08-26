<?php

namespace Tests\Unit;

use App\Enums\ArticleStatus;
use App\Enums\CommentStatus;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Comment;
use App\Models\User;
use App\Policies\ArticlePolicy;
use App\Policies\CommentPolicy;
use App\Policies\UserPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The policies, exercised directly rather than through HTTP.
 *
 * AdminAuthorizationTest already proves the routes consult these. This covers
 * the decision table itself — the branches a route test would need a fixture
 * apiece to reach, notably CommentPolicy's edit window, which is a function of
 * the clock and cannot be reached from a request at all without travelling.
 *
 * No database: policies read plain attributes, so the models are hydrated in
 * memory with forceFill. That keeps this suite fast and makes the inputs to
 * each decision visible in one line.
 */
class PolicyTest extends TestCase
{
    private function user(UserRole $role, int $id = 1): User
    {
        return (new User)->forceFill(['id' => $id, 'role' => $role]);
    }

    private function article(int $authorId, ArticleStatus $status): Article
    {
        return (new Article)->forceFill(['id' => 100, 'author_id' => $authorId, 'status' => $status]);
    }

    private function comment(int $userId, CommentStatus $status, $createdAt): Comment
    {
        return (new Comment)->forceFill(['id' => 200, 'user_id' => $userId, 'status' => $status, 'created_at' => $createdAt]);
    }

    // ── Roles ────────────────────────────────────────────────────────────

    public function test_the_role_ladder(): void
    {
        $this->assertTrue(UserRole::Admin->canManageSite());
        $this->assertFalse(UserRole::Editor->canManageSite());

        $this->assertTrue(UserRole::Editor->canPublish());
        $this->assertFalse(UserRole::Reporter->canPublish());

        $this->assertTrue(UserRole::Reporter->isStaff());
        $this->assertFalse(UserRole::Reader->isStaff());

        $this->assertTrue(UserRole::Editor->canModerate());
        $this->assertFalse(UserRole::Reporter->canModerate());
    }

    // ── ArticlePolicy ────────────────────────────────────────────────────

    public function test_a_reporter_may_update_their_own_story_only_until_it_is_published(): void
    {
        $policy = new ArticlePolicy;
        $reporter = $this->user(UserRole::Reporter, 7);

        $this->assertTrue($policy->update($reporter, $this->article(7, ArticleStatus::Draft)));
        $this->assertTrue($policy->update($reporter, $this->article(7, ArticleStatus::Review)));

        // Published copy belongs to the desk.
        $this->assertFalse($policy->update($reporter, $this->article(7, ArticleStatus::Published)));

        // And never somebody else's, at any status.
        $this->assertFalse($policy->update($reporter, $this->article(9, ArticleStatus::Draft)));
    }

    public function test_an_editor_may_update_anything(): void
    {
        $policy = new ArticlePolicy;
        $editor = $this->user(UserRole::Editor, 3);

        $this->assertTrue($policy->update($editor, $this->article(9, ArticleStatus::Published)));
    }

    public function test_only_editors_and_up_may_publish_or_place(): void
    {
        $policy = new ArticlePolicy;

        $this->assertTrue($policy->publish($this->user(UserRole::Admin)));
        $this->assertTrue($policy->publish($this->user(UserRole::Editor)));
        $this->assertFalse($policy->publish($this->user(UserRole::Reporter)));

        // Homepage placement is an editorial decision, not a writer's.
        $this->assertFalse($policy->feature($this->user(UserRole::Reporter)));
    }

    public function test_delete_narrows_as_the_role_narrows(): void
    {
        $policy = new ArticlePolicy;

        // Admin: anything.
        $this->assertTrue($policy->delete($this->user(UserRole::Admin, 1), $this->article(9, ArticleStatus::Published)));

        // Editor: anything not yet published.
        $editor = $this->user(UserRole::Editor, 2);
        $this->assertTrue($policy->delete($editor, $this->article(9, ArticleStatus::Draft)));
        $this->assertFalse($policy->delete($editor, $this->article(9, ArticleStatus::Published)));

        // Reporter: only their own drafts.
        $reporter = $this->user(UserRole::Reporter, 7);
        $this->assertTrue($policy->delete($reporter, $this->article(7, ArticleStatus::Draft)));
        $this->assertFalse($policy->delete($reporter, $this->article(7, ArticleStatus::Review)));
        $this->assertFalse($policy->delete($reporter, $this->article(9, ArticleStatus::Draft)));
    }

    // ── CommentPolicy: the 15-minute edit window ─────────────────────────

    public static function editWindow(): array
    {
        return [
            'just posted' => [1, true],
            'inside the window' => [14, true],
            'past the window' => [16, false],
            'long past' => [60 * 24, false],
        ];
    }

    #[DataProvider('editWindow')]
    public function test_an_author_may_edit_their_comment_only_inside_the_window(int $minutesAgo, bool $expected): void
    {
        $policy = new CommentPolicy;
        $author = $this->user(UserRole::Reader, 5);

        $comment = $this->comment(5, CommentStatus::Approved, now()->subMinutes($minutesAgo));

        $this->assertSame($expected, $policy->update($author, $comment));
    }

    public function test_a_moderator_is_not_bound_by_the_window(): void
    {
        $policy = new CommentPolicy;
        $old = $this->comment(5, CommentStatus::Approved, now()->subDays(30));

        $this->assertTrue($policy->update($this->user(UserRole::Editor, 2), $old));
    }

    public function test_a_rejected_comment_cannot_be_edited_back_by_its_author(): void
    {
        // Otherwise the author edits around the moderator's decision.
        $policy = new CommentPolicy;
        $author = $this->user(UserRole::Reader, 5);

        $rejected = $this->comment(5, CommentStatus::Rejected, now()->subMinute());

        $this->assertFalse($policy->update($author, $rejected));
    }

    public function test_an_author_may_always_delete_their_own_comment(): void
    {
        $policy = new CommentPolicy;
        $author = $this->user(UserRole::Reader, 5);

        $this->assertTrue($policy->delete($author, $this->comment(5, CommentStatus::Approved, now()->subYear())));
        $this->assertFalse($policy->delete($author, $this->comment(9, CommentStatus::Approved, now())));
    }

    // ── UserPolicy ───────────────────────────────────────────────────────

    public function test_nobody_deletes_or_demotes_themselves(): void
    {
        $policy = new UserPolicy;
        $admin = $this->user(UserRole::Admin, 1);

        $this->assertFalse($policy->delete($admin, $admin));
        $this->assertFalse($policy->changeRole($admin, $admin));

        $someoneElse = $this->user(UserRole::Editor, 2);
        $this->assertTrue($policy->delete($admin, $someoneElse));
        $this->assertTrue($policy->changeRole($admin, $someoneElse));
    }

    public function test_a_non_admin_cannot_manage_other_users(): void
    {
        $policy = new UserPolicy;
        $editor = $this->user(UserRole::Editor, 2);
        $other = $this->user(UserRole::Reporter, 3);

        $this->assertFalse($policy->viewAny($editor));
        $this->assertFalse($policy->update($editor, $other));

        // But anyone may edit their own profile.
        $this->assertTrue($policy->update($editor, $editor));
    }
}
