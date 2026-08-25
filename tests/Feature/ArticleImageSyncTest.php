<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\Media;
use App\Models\User;
use App\Services\ArticleQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `image` and `image_id` have to move together.
 *
 * The denormalised `image` feeds the plain `src`; `image_id` feeds the srcset.
 * A browser handed both attributes selects from `srcset` and ignores `src`
 * entirely, so an article whose columns disagree serves the *old* picture while
 * every admin screen shows the new one. The editor posted only `image` for a
 * while, and nothing caught it because it stayed invisible until articles had
 * an `image_id` to be stale.
 */
class ArticleImageSyncTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An editor hydrated from the database, not straight from the factory.
     *
     * `create()` returns a model carrying only the attributes it inserted, and
     * `UserFactory` never sets `avatar`. Strict mode's
     * preventAccessingMissingAttributes then throws the moment the admin layout
     * reads `avatar_url` — a test-only failure, since a real request always has
     * a user loaded from the row.
     */
    private function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor])->fresh();
    }

    private function payloadFor(Article $article, array $overrides = []): array
    {
        return array_merge([
            'title' => $article->title,
            'body' => $article->body,
            'category_id' => $article->category_id,
            'type' => ArticleType::News->value,
            'status' => ArticleStatus::Published->value,
            'locale' => 'bn',
        ], $overrides);
    }

    public function test_updating_the_lead_image_moves_both_columns(): void
    {
        $editor = $this->editor();
        $old = Media::factory()->create();
        $new = Media::factory()->create();

        $article = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $old->id,
            'image' => $old->path,
        ]);

        $this->actingAs($editor)
            ->put(route('admin.articles.update', $article), $this->payloadFor($article, [
                'image' => $new->path,
                'image_id' => $new->id,
            ]))
            ->assertRedirect();

        $article->refresh();

        $this->assertSame($new->id, $article->image_id);
        $this->assertSame($new->path, $article->image);

        // The pair only matters because of what it renders. Assert the markup,
        // not just the columns.
        $card = ArticleQuery::cards()->whereKey($article->id)->firstOrFail();

        $this->assertStringContainsString($new->path, $card->image_url);
        $this->assertStringContainsString(pathinfo($new->path, PATHINFO_FILENAME), $card->image_srcset);
        $this->assertStringNotContainsString(pathinfo($old->path, PATHINFO_FILENAME), $card->image_srcset);
    }

    public function test_clearing_the_lead_image_clears_both_columns(): void
    {
        $editor = $this->editor();
        $media = Media::factory()->create();

        $article = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $media->id,
            'image' => $media->path,
        ]);

        $this->actingAs($editor)
            ->put(route('admin.articles.update', $article), $this->payloadFor($article, [
                'image' => null,
                'image_id' => null,
            ]))
            ->assertRedirect();

        $article->refresh();

        $this->assertNull($article->image_id);
        $this->assertNull($article->image);

        // A srcset left behind by a cleared image would render an article with
        // no `src` but a full ladder pointing at the deleted picture.
        $card = ArticleQuery::cards()->whereKey($article->id)->firstOrFail();

        $this->assertNull($card->image_url);
        $this->assertNull($card->image_srcset);
    }

    public function test_changing_the_image_deletes_the_old_one(): void
    {
        Storage::fake('public');

        $old = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/old.jpg']);
        $new = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/new.jpg']);

        Storage::disk('public')->put('uploads/x/old.jpg', 'original');
        Storage::disk('public')->put($old->conversions['w768'], 'derivative');

        $article = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $old->id,
            'image' => $old->path,
        ]);

        $this->actingAs($this->editor())
            ->put(route('admin.articles.update', $article), $this->payloadFor($article, [
                'image' => $new->path,
                'image_id' => $new->id,
            ]))
            ->assertRedirect();

        // Row, original and derivatives all go: otherwise every image change an
        // editor makes leaks six files and a row nothing will point at again.
        $this->assertDatabaseMissing('media', ['id' => $old->id]);
        Storage::disk('public')->assertMissing('uploads/x/old.jpg');
        Storage::disk('public')->assertMissing($old->conversions['w768']);
    }

    public function test_changing_the_image_keeps_a_file_another_article_shares(): void
    {
        Storage::fake('public');

        $shared = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/shared.jpg']);
        $new = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/new.jpg']);

        Storage::disk('public')->put('uploads/x/shared.jpg', 'original');

        $article = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $shared->id,
            'image' => $shared->path,
        ]);

        // image_id is nullOnDelete, so reaping the row here would silently
        // blank this second article's lead image.
        $other = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $shared->id,
            'image' => $shared->path,
        ]);

        $this->actingAs($this->editor())
            ->put(route('admin.articles.update', $article), $this->payloadFor($article, [
                'image' => $new->path,
                'image_id' => $new->id,
            ]))
            ->assertRedirect();

        $this->assertDatabaseHas('media', ['id' => $shared->id]);
        Storage::disk('public')->assertExists('uploads/x/shared.jpg');
        $this->assertSame($shared->id, $other->fresh()->image_id);
    }

    public function test_a_soft_deleted_article_still_protects_its_image(): void
    {
        Storage::fake('public');

        $shared = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/shared.jpg']);
        $new = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/new.jpg']);

        Storage::disk('public')->put('uploads/x/shared.jpg', 'original');

        $article = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $shared->id,
            'image' => $shared->path,
        ]);

        // Trashed, not gone. Restoring it after the file was reaped would leave
        // it pointing at nothing, which is why the reference check runs on the
        // query builder rather than through Eloquent's scopes.
        $trashed = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $shared->id,
            'image' => $shared->path,
        ]);
        $trashed->delete();

        $this->actingAs($this->editor())
            ->put(route('admin.articles.update', $article), $this->payloadFor($article, [
                'image' => $new->path,
                'image_id' => $new->id,
            ]))
            ->assertRedirect();

        Storage::disk('public')->assertExists('uploads/x/shared.jpg');
        $this->assertDatabaseHas('media', ['id' => $shared->id]);
    }

    public function test_the_editor_form_posts_the_media_id(): void
    {
        $editor = $this->editor();
        $media = Media::factory()->create();

        $article = Article::factory()->create([
            'category_id' => Category::factory(),
            'image_id' => $media->id,
            'image' => $media->path,
        ]);

        // The regression was purely that this field did not exist in the form,
        // so the request never carried it and image_id kept its old value.
        $this->actingAs($editor)
            ->get(route('admin.articles.edit', $article))
            ->assertOk()
            ->assertSee('name="image_id"', false);
    }
}
