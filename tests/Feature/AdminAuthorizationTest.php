<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin authorisation matrix.
 *
 * Hiding a nav link is not access control, so every screen is requested
 * directly by URL as each role. This is the coverage that was previously an
 * ad-hoc script — the one thing that would catch a `Gate::authorize()` dropped
 * during a refactor, which no lint pass and no manual click-through would.
 *
 * Three gates decide almost everything:
 *   `staff`          — admin, editor, reporter (a reader gets 404, not 403)
 *   manage-taxonomy  — admin, editor
 *   manage-site      — admin only
 */
class AdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        // ->fresh(): a factory-built model holds only the attributes the
        // factory set, and strict mode throws on anything else the admin
        // layout reads. Hydrating from the row avoids that.
        return User::factory()->{$role}()->create()->fresh();
    }

    // ── Who may enter /admin at all ──────────────────────────────────────

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_readers_get_a_404_rather_than_a_403(): void
    {
        // Deliberate: a reader should not have the admin panel's existence
        // confirmed to them.
        $this->actingAs(User::factory()->create()->fresh())
            ->get('/admin')
            ->assertNotFound();
    }

    #[DataProvider('staffRoles')]
    public function test_staff_reach_the_dashboard(string $role): void
    {
        $this->actingAs($this->user($role))->get('/admin')->assertOk();
    }

    public static function staffRoles(): array
    {
        return ['admin' => ['admin'], 'editor' => ['editor'], 'reporter' => ['reporter']];
    }

    // ── The screen matrix ────────────────────────────────────────────────

    /**
     * Each row: path, and the roles that may GET it. Any staff role not listed
     * must be refused — 403 from the gate, since `staff` already let them in.
     */
    public static function adminScreens(): array
    {
        return [
            'articles'   => ['/admin/articles',        ['admin', 'editor', 'reporter']],
            'new article'=> ['/admin/articles/create', ['admin', 'editor', 'reporter']],
            'media'      => ['/admin/media',           ['admin', 'editor', 'reporter']],
            'comments'   => ['/admin/comments',        ['admin', 'editor']],
            'categories' => ['/admin/categories',      ['admin', 'editor']],
            'taxonomy'   => ['/admin/taxonomy',        ['admin', 'editor']],
            'layout'     => ['/admin/layout',          ['admin', 'editor']],
            'users'      => ['/admin/users',           ['admin']],
            'ads'        => ['/admin/ads',             ['admin']],
            'pages'      => ['/admin/pages',           ['admin']],
            'settings'   => ['/admin/settings',        ['admin']],
        ];
    }

    #[DataProvider('adminScreens')]
    public function test_admin_screen_is_reachable_by_exactly_the_right_roles(string $path, array $allowed): void
    {
        foreach (['admin', 'editor', 'reporter'] as $role) {
            $response = $this->actingAs($this->user($role))->get($path);

            if (in_array($role, $allowed, true)) {
                $response->assertOk("{$role} should reach {$path}");
            } else {
                $response->assertForbidden("{$role} should not reach {$path}");
            }
        }
    }

    // ── Actions, not just screens ────────────────────────────────────────

    public function test_a_reporter_cannot_publish(): void
    {
        $reporter = $this->user('reporter');
        $article = Article::factory()->draft()->create(['author_id' => $reporter->id]);

        $this->actingAs($reporter)
            ->patch("/admin/articles/{$article->id}/status", ['status' => 'published'])
            ->assertForbidden();

        $this->assertSame('draft', $article->fresh()->status->value);
    }

    public function test_an_editor_can_publish(): void
    {
        $article = Article::factory()->draft()->create();

        $this->actingAs($this->user('editor'))
            ->patch("/admin/articles/{$article->id}/status", ['status' => 'published'])
            ->assertRedirect();

        $this->assertSame('published', $article->fresh()->status->value);
    }

    public function test_a_reporter_cannot_edit_another_authors_story(): void
    {
        $someone_else = Article::factory()->draft()->create();

        $this->actingAs($this->user('reporter'))
            ->get("/admin/articles/{$someone_else->id}/edit")
            ->assertForbidden();
    }

    public function test_a_reporter_may_edit_their_own_draft_but_not_once_it_is_published(): void
    {
        $reporter = $this->user('reporter');

        $draft = Article::factory()->draft()->create(['author_id' => $reporter->id]);
        $this->actingAs($reporter)->get("/admin/articles/{$draft->id}/edit")->assertOk();

        // Once published it is the desk's copy, not the writer's.
        $published = Article::factory()->create([
            'author_id' => $reporter->id,
            'published_at' => now()->subHour(),
        ]);

        $this->actingAs($reporter)
            ->put("/admin/articles/{$published->id}", [
                // A complete, valid payload on purpose: ArticleRequest is
                // resolved before the controller body, so an incomplete one
                // would bounce on validation and never reach the gate — the
                // test would pass without proving anything.
                'title' => 'বদলে দেওয়ার চেষ্টা',
                'category_id' => Category::factory()->create()->id,
                'body' => '<p>x</p>',
                'type' => 'news',
                'status' => 'published',
                'locale' => 'bn',
            ])
            ->assertForbidden();

        $this->assertNotSame('বদলে দেওয়ার চেষ্টা', $published->fresh()->title);
    }

    public function test_a_reporter_cannot_delete_from_the_shared_media_library(): void
    {
        // Uploading and captioning are part of filing a story; deleting removes
        // the original and every derivative from disk, and the library is
        // shared across the newsroom.
        $media = Media::factory()->create();

        $this->actingAs($this->user('reporter'))
            ->delete("/admin/media/{$media->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        // Guards against locking the site out of its last administrator.
        $admin = $this->user('admin');

        // User::getRouteKeyName() is 'slug' — for /author/{slug} — and the
        // admin routes bind by it too, so an id here would 404 and the test
        // would look like it passed for the wrong reason.
        $this->actingAs($admin)
            ->delete("/admin/users/{$admin->slug}")
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }
}
