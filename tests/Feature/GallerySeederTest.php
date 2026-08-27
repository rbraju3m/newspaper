<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\GallerySeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GallerySeeder` — the demo content behind `/photo`.
 *
 * It stores no files, so unlike the other imagery seeders these tests are
 * plain row assertions and cost nothing. What is worth pinning is the pool
 * rule: the seeder curates the demo library and must not reach into an
 * editor's own uploads, because publishing somebody's private upload into a
 * demo gallery is the kind of surprise that is only noticed after the fact.
 */
class GallerySeederTest extends TestCase
{
    use RefreshDatabase;

    /** Imagery the seeder is allowed to use: the `photos:import` folder. */
    private function photographs(int $count): void
    {
        foreach (range(1, $count) as $n) {
            Media::factory()->create([
                'path' => "uploads/2026/08/photos/{$n}.jpg",
                'filename' => "{$n}.jpg",
                'credit' => 'ছবি: প্রথম আলো',
            ]);
        }
    }

    public function test_it_fills_the_hub_from_the_media_library(): void
    {
        $this->photographs(99);

        $this->seed(GallerySeeder::class);

        $galleries = Gallery::withCount('images')->get();

        $this->assertCount(7, $galleries);
        $this->assertCount(6, $galleries->where('status', 'published'),
            'Six published galleries fill the homepage photo row exactly.');

        foreach ($galleries as $gallery) {
            $this->assertGreaterThan(0, $gallery->images_count);
            $this->assertNotNull($gallery->cover, 'A coverless gallery renders an empty box in the photo row.');
            $this->assertNotNull($gallery->slug);

            $first = $gallery->images()->orderBy('position')->first();

            $this->assertSame($first->path, $gallery->cover);
            $this->assertSame(range(0, $gallery->images_count - 1),
                $gallery->images()->orderBy('position')->pluck('position')->map('intval')->all());
        }
    }

    /** The count is maintained by model events, so it must be right without a reconcile. */
    public function test_the_denormalised_image_count_is_right_straight_out_of_the_seeder(): void
    {
        $this->photographs(99);

        $this->seed(GallerySeeder::class);

        foreach (Gallery::all() as $gallery) {
            $this->assertSame(
                $gallery->images()->count(),
                (int) $gallery->getRawOriginal('images_count'),
                "{$gallery->title} carries a stale count — `withCount()` would hide it.",
            );
        }
    }

    public function test_every_image_carries_the_photographs_own_credit(): void
    {
        $this->photographs(99);

        $this->seed(GallerySeeder::class);

        $this->assertSame(62, GalleryImage::count());
        $this->assertSame(62, GalleryImage::where('credit', 'ছবি: প্রথম আলো')->count(),
            'The credit belongs to the photograph, not to the seeder.');

        // One gallery is deliberately left uncaptioned: credit-without-caption
        // is the normal state of a gallery just filled, and is the combination
        // that rendered as nothing until `photo-show` was fixed.
        $bare = Gallery::where('title', 'উৎসবের রং')->sole();

        $this->assertSame(0, $bare->images()->whereNotNull('caption')->count());
        $this->assertSame(8, $bare->images()->whereNotNull('credit')->count());

        $captioned = Gallery::where('title', 'ঢাকার সকাল')->sole();

        $this->assertSame(10, $captioned->images()->whereNotNull('caption')->count());
        $this->assertSame(10, $captioned->images()->distinct()->count('caption'),
            'Captions repeating every few frames looks more broken than none at all.');
    }

    /** The draft proves the status filter is real — it is admin-only content. */
    public function test_the_draft_gallery_is_not_published(): void
    {
        $this->photographs(99);

        $this->seed(GallerySeeder::class);

        $draft = Gallery::where('title', 'সীমান্তের দিনরাত')->sole();

        $this->assertSame('draft', $draft->status);
        $this->assertNull($draft->published_at);
        $this->assertSame(0, Gallery::published()->whereKey($draft->id)->count());
    }

    public function test_it_spreads_the_library_rather_than_dealing_it_in_runs(): void
    {
        $this->photographs(99);

        $this->seed(GallerySeeder::class);

        $used = GalleryImage::pluck('media_id');

        $this->assertSame($used->count(), $used->unique()->count(),
            'With a pool this size no photograph should have to be used twice.');

        foreach (Gallery::all() as $gallery) {
            $ids = $gallery->images()->pluck('media_id');

            $this->assertSame($ids->count(), $ids->unique()->count(),
                "{$gallery->title} attached the same photograph twice.");
        }
    }

    /**
     * A pool smaller than the 62 slots is the fresh-box case, not an edge one:
     * `MediaSeeder` draws three plates for each of the 18 sections, so a box
     * where `photos:import` has never run curates 62 slots out of 54 images.
     * Sharing across galleries is fine and unavoidable; the same image twice
     * inside one gallery is not.
     */
    #[DataProvider('smallPools')]
    public function test_a_pool_smaller_than_the_galleries_still_deals_without_repeats(int $pool): void
    {
        $this->photographs($pool);

        $this->seed(GallerySeeder::class);

        $this->assertSame(7, Gallery::count());

        foreach (Gallery::all() as $gallery) {
            $ids = $gallery->images()->pluck('media_id');

            $this->assertLessThanOrEqual($pool, $ids->count(),
                'A gallery cannot hold more images than the pool has.');
            $this->assertSame($ids->count(), $ids->unique()->count(),
                "{$gallery->title} attached the same image twice out of a pool of {$pool}.");
        }
    }

    /** @return array<string, array{int}> */
    public static function smallPools(): array
    {
        return [
            'a fresh box: 18 sections x 3 plates' => [54],
            'barely anything at all' => [5],
            'one image' => [1],
        ];
    }

    public function test_re_running_adds_nothing(): void
    {
        $this->photographs(99);

        $this->seed(GallerySeeder::class);
        $this->seed(GallerySeeder::class);

        $this->assertSame(7, Gallery::count());
        $this->assertSame(62, GalleryImage::count());
    }

    /** A gallery made through the admin under one of these titles is somebody's work. */
    public function test_it_leaves_a_hand_made_gallery_alone(): void
    {
        $this->photographs(99);

        $mine = Gallery::create([
            'title' => 'ঢাকার সকাল',
            'status' => 'draft',
            'description' => 'আমার নিজের।',
        ]);

        $mine->images()->create(['path' => 'uploads/mine/one.jpg', 'position' => 0]);

        $this->seed(GallerySeeder::class);

        $mine->refresh();

        $this->assertSame(1, $mine->images()->count());
        $this->assertSame('uploads/mine/one.jpg', $mine->images()->sole()->path);
        $this->assertSame('draft', $mine->status);
        $this->assertSame('আমার নিজের।', $mine->description);
        $this->assertSame(7, Gallery::count(), 'The other six are still seeded around it.');
    }

    /**
     * The pool is seeded imagery only. An ad creative, an e-paper page and an
     * editor's own upload all sit in the same `media` table, and none of them
     * belongs in a photo gallery.
     */
    public function test_it_curates_only_the_imagery_seeding_owns(): void
    {
        $this->photographs(40);

        $ad = Media::factory()->create([
            'path' => 'uploads/2026/08/ads/seed-ad-home-billboard.jpg',
            'filename' => 'seed-ad-home_billboard.jpg',
        ]);

        $page = Media::factory()->create([
            'path' => 'uploads/2026/08/epaper-2026-08-27-main/1.jpg',
            'filename' => '1.jpg',
        ]);

        $mine = Media::factory()->create([
            'path' => 'uploads/2026/08/আমার-প্রতিবেদন/private.jpg',
            'filename' => 'private.jpg',
        ]);

        $this->seed(GallerySeeder::class);

        $used = GalleryImage::pluck('media_id');

        $this->assertNotContains($ad->id, $used, 'An ad creative is not photojournalism.');
        $this->assertNotContains($page->id, $used, 'A broadsheet page in a photo gallery reads as a mistake.');
        $this->assertNotContains($mine->id, $used, "An editor's own upload is not demo material.");
    }

    /** A plate library is the fallback when nothing has been imported. */
    public function test_it_falls_back_to_the_drawn_plates(): void
    {
        Media::factory()->create([
            'path' => 'uploads/2026/08/sports/seed-sports-1.jpg',
            'filename' => 'seed-sports-1.jpg',
            'credit' => 'ছবি: প্রতীকী (ডেমো)',
        ]);

        $this->seed(GallerySeeder::class);

        $this->assertSame(7, Gallery::count());
        $this->assertSame(7, GalleryImage::where('credit', 'ছবি: প্রতীকী (ডেমো)')->count());
    }

    public function test_it_skips_entirely_when_the_library_holds_nothing_it_may_use(): void
    {
        Media::factory()->create([
            'path' => 'uploads/2026/08/ads/seed-ad-home-billboard.jpg',
            'filename' => 'seed-ad-home_billboard.jpg',
        ]);

        $this->seed(GallerySeeder::class);

        $this->assertSame(0, Gallery::count(), 'An empty gallery is worse than no gallery.');
    }

    public function test_the_galleries_are_filed_by_a_member_of_staff(): void
    {
        $this->photographs(99);

        $editor = User::factory()->editor()->create();
        User::factory()->admin()->create();

        $this->seed(GallerySeeder::class);

        $this->assertSame([$editor->id], Gallery::pluck('user_id')->unique()->all(),
            'A photo gallery is the desk’s work — editor before admin.');
    }
}
