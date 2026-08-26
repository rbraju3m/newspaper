<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Media;
use Database\Seeders\MediaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `MediaSeeder` — specifically, what it refuses to touch.
 *
 * It exists to heal articles pointing at imagery that is not there. It used to
 * do that by relinking every article on every run, which is indistinguishable
 * from healing right up until the imagery is somebody's deliberate choice: a
 * real editor upload, or a folder brought in by `photos:import`. Re-seeding to
 * repair one broken ad silently replaced every photograph on the site.
 *
 * The drawn plate library is only built when something actually needs it, so
 * the skip path below costs no GD work at all.
 */
class MediaSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD has no WebP support.');
        }
    }

    /** An article with imagery that genuinely exists: media row and file. */
    private function articleWithImageOnDisk(): array
    {
        $path = 'uploads/2026/08/photos/1.jpg';

        Storage::disk('public')->makeDirectory('uploads/2026/08/photos');

        $im = imagecreatetruecolor(1200, 800);
        imagejpeg($im, Storage::disk('public')->path($path), 85);
        imagedestroy($im);

        $media = Media::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'filename' => '1.jpg',
            'mime' => 'image/jpeg',
            'width' => 1200,
            'height' => 800,
        ]);

        $article = Article::factory()->create([
            'image_id' => $media->id,
            'image' => $path,
            'image_credit' => 'ছবি: প্রথম আলো',
        ]);

        return [$article, $media];
    }

    public function test_it_leaves_an_article_whose_imagery_is_on_disk_alone(): void
    {
        [$article, $media] = $this->articleWithImageOnDisk();

        $this->seed(MediaSeeder::class);

        $article->refresh();

        $this->assertSame($media->id, $article->image_id, 'A working photograph is not the seeder to replace.');
        $this->assertSame($media->path, $article->image);
        $this->assertSame('ছবি: প্রথম আলো', $article->image_credit);
    }

    public function test_it_draws_no_plates_when_nothing_needs_them(): void
    {
        [, $media] = $this->articleWithImageOnDisk();

        $this->seed(MediaSeeder::class);

        // The library is built lazily, so a box whose articles all carry real
        // photographs gains no orphan plates and pays no GD cost.
        $this->assertSame(
            [$media->id],
            Media::query()->pluck('id')->all(),
            'The plate library was drawn even though no article needed it.'
        );
    }

    public function test_it_relinks_an_article_whose_file_has_vanished(): void
    {
        [$article, $media] = $this->articleWithImageOnDisk();

        Storage::disk('public')->delete($media->path);

        $this->seed(MediaSeeder::class);

        $article->refresh();

        $this->assertNotSame($media->id, $article->image_id, 'A path with no file behind it is exactly what this seeder is for.');
        $this->assertNotNull($article->image_id);
        Storage::disk('public')->assertExists($article->image);
    }

    public function test_it_relinks_an_article_whose_media_row_is_gone(): void
    {
        [$article] = $this->articleWithImageOnDisk();

        // The file survives, the row does not — so srcset has nothing to build
        // from even though the plain src still resolves.
        Media::query()->whereKey($article->image_id)->delete();

        $this->seed(MediaSeeder::class);

        $article->refresh();

        $this->assertNotNull($article->image_id);
        $this->assertNotNull(Media::query()->find($article->image_id));
    }

    public function test_it_links_an_article_that_never_had_imagery(): void
    {
        $article = Article::factory()->create(['image_id' => null, 'image' => 'seed/4.jpg']);

        $this->seed(MediaSeeder::class);

        $article->refresh();

        $this->assertNotNull($article->image_id);
        Storage::disk('public')->assertExists($article->image);
    }

    public function test_it_fills_an_ad_whose_creative_is_missing(): void
    {
        $this->articleWithImageOnDisk();

        $ad = \App\Models\Ad::create([
            'title' => 'ডেমো বিজ্ঞাপন',
            'type' => 'image',
            'position' => 'home_billboard',
            'asset' => 'uploads/2026/08/ads/gone.jpg',
            'is_active' => true,
        ]);

        $this->seed(MediaSeeder::class);

        $ad->refresh();

        $this->assertNotSame('uploads/2026/08/ads/gone.jpg', $ad->asset);
        Storage::disk('public')->assertExists($ad->asset);
    }

    /** Guards the section lookup: a leaf category resolves through `path`. */
    public function test_a_nested_category_still_gets_a_plate(): void
    {
        $parent = Category::factory()->create(['parent_id' => null]);
        $child = Category::factory()->create(['parent_id' => $parent->id]);

        $article = Article::factory()->create([
            'category_id' => $child->id,
            'image_id' => null,
            'image' => null,
        ]);

        $this->seed(MediaSeeder::class);

        $this->assertNotNull($article->fresh()->image_id);
    }
}
