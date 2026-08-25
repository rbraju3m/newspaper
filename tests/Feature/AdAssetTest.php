<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\Article;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ad creatives and the media library.
 *
 * Two defects lived here. Uploads went straight to disk with
 * `$file->store('ads', 'public')`, so a creative had no `Media` row — nothing
 * tracked it, nothing could re-derive it, `media:backfill` could not see it.
 * And replacing or deleting an ad removed `$ad->asset` by raw path without
 * asking who owned the file, which orphaned any media row pointing at it.
 */
class AdAssetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        // ->fresh(): strict mode rejects reading attributes the factory never
        // set, and the admin layout reads avatar_url.
        return User::factory()->create(['role' => UserRole::Admin])->fresh();
    }

    private function upload(): UploadedFile
    {
        return UploadedFile::fake()->image('creative.jpg', 728, 90);
    }

    public function test_an_uploaded_creative_becomes_a_tracked_media_row(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post(route('admin.ads.store'), [
                'title' => 'ডেমো',
                'position' => 'header_leaderboard',
                'type' => 'image',
                'file' => $this->upload(),
            ])
            ->assertRedirect();

        $ad = Ad::query()->firstOrFail();

        $media = Media::query()->where('path', $ad->asset)->first();

        $this->assertNotNull($media, 'the creative should be in the media library');
        $this->assertSame('creative.jpg', $media->filename);
        $this->assertArrayHasKey('w320', $media->conversions ?? []);
    }

    public function test_replacing_a_creative_deletes_the_old_one_when_nothing_else_uses_it(): void
    {
        Storage::fake('public');

        $media = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/only.jpg']);
        Storage::disk('public')->put('uploads/x/only.jpg', 'original');
        Storage::disk('public')->put($media->conversions['w320'], 'derivative');

        $ad = Ad::query()->create([
            'title' => 'ডেমো', 'position' => 'header_leaderboard',
            'type' => 'image', 'asset' => $media->path, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.ads.update', $ad), [
                'title' => 'ডেমো', 'position' => 'header_leaderboard',
                'type' => 'image', 'file' => $this->upload(),
            ])
            ->assertRedirect();

        // Row, original and every derivative — otherwise each replacement leaks
        // six files and a row that nothing will ever point at again.
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing('uploads/x/only.jpg');
        Storage::disk('public')->assertMissing($media->conversions['w320']);
    }

    public function test_replacing_a_creative_keeps_a_file_another_row_still_uses(): void
    {
        Storage::fake('public');

        $media = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/shared.jpg']);
        Storage::disk('public')->put('uploads/x/shared.jpg', 'original');

        // The file is shared: an article uses it as its lead image. Deleting it
        // with the ad would blank that article, because image_id is nullOnDelete.
        $article = Article::factory()->create(['image_id' => $media->id, 'image' => $media->path]);

        $ad = Ad::query()->create([
            'title' => 'ডেমো', 'position' => 'header_leaderboard',
            'type' => 'image', 'asset' => $media->path, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.ads.update', $ad), [
                'title' => 'ডেমো', 'position' => 'header_leaderboard',
                'type' => 'image', 'file' => $this->upload(),
            ])
            ->assertRedirect();

        Storage::disk('public')->assertExists('uploads/x/shared.jpg');
        $this->assertDatabaseHas('media', ['id' => $media->id]);
        $this->assertSame($media->id, $article->fresh()->image_id);

        // The ad still moved on to its new creative.
        $this->assertNotSame($media->path, $ad->fresh()->asset);
    }

    public function test_replacing_an_untracked_creative_still_removes_the_file(): void
    {
        Storage::fake('public');

        // A legacy upload from before ads used the media library: nothing else
        // can reference it, so leaving it behind would just leak.
        Storage::disk('public')->put('ads/legacy.jpg', 'bytes');

        $ad = Ad::query()->create([
            'title' => 'ডেমো', 'position' => 'header_leaderboard',
            'type' => 'image', 'asset' => 'ads/legacy.jpg', 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.ads.update', $ad), [
                'title' => 'ডেমো', 'position' => 'header_leaderboard',
                'type' => 'image', 'file' => $this->upload(),
            ])
            ->assertRedirect();

        Storage::disk('public')->assertMissing('ads/legacy.jpg');
    }

    public function test_deleting_an_ad_releases_its_creative(): void
    {
        Storage::fake('public');

        $media = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/only.jpg']);
        Storage::disk('public')->put('uploads/x/only.jpg', 'original');

        $ad = Ad::query()->create([
            'title' => 'ডেমো', 'position' => 'header_leaderboard',
            'type' => 'image', 'asset' => $media->path, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.ads.destroy', $ad))
            ->assertRedirect();

        $this->assertDatabaseMissing('ads', ['id' => $ad->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing('uploads/x/only.jpg');
    }

    public function test_deleting_an_ad_keeps_a_creative_an_article_still_uses(): void
    {
        Storage::fake('public');

        $media = Media::factory()->create(['disk' => 'public', 'path' => 'uploads/x/shared.jpg']);
        Storage::disk('public')->put('uploads/x/shared.jpg', 'original');

        Article::factory()->create(['image_id' => $media->id, 'image' => $media->path]);

        $ad = Ad::query()->create([
            'title' => 'ডেমো', 'position' => 'header_leaderboard',
            'type' => 'image', 'asset' => $media->path, 'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.ads.destroy', $ad))
            ->assertRedirect();

        Storage::disk('public')->assertExists('uploads/x/shared.jpg');
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }
}
