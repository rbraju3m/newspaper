<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\Media;
use App\Models\User;
use App\Services\AdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ad creatives are served at the size the slot needs, not the size they were
 * uploaded at.
 *
 * Uploads have gone through `ImageService` for a while, so every creative
 * already had a WebP derivative ladder — but `ads` kept only `asset`, a bare
 * path, and the slot rendered that. The ladder existed and nothing could reach
 * it, so a phone loading the front page was sent a 970px billboard.
 *
 * The half of this worth testing carefully is not the `srcset` string. It is
 * the three ways this particular change could break something else: strict
 * mode on a relation nobody eager-loaded, a cached payload holding a class the
 * store is not allowed to unserialize, and a slot losing its reserved box.
 */
class AdCreativeSizingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin])->fresh();
    }

    private function upload(string $name = 'creative.jpg', int $w = 970, int $h = 250): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    private function createAd(array $overrides = []): Ad
    {
        $this->actingAs($this->admin())->post('/admin/ads', [
            'title' => 'ঈদ অফার',
            'position' => 'home_billboard',
            'type' => 'image',
            'url' => 'https://example.com/offer',
            'is_active' => '1',
            'priority' => 5,
            'file' => $this->upload(),
            ...$overrides,
        ])->assertRedirect();

        return Ad::latest('id')->firstOrFail();
    }

    // ── The link that makes the ladder reachable ─────────────────────────

    public function test_an_uploaded_creative_records_its_media_row(): void
    {
        $ad = $this->createAd();

        $this->assertNotNull($ad->media_id, 'The upload created a media row and threw the id away.');
        $this->assertSame($ad->asset, Media::findOrFail($ad->media_id)->path);
    }

    public function test_the_slot_offers_the_ladder_and_a_matching_sizes(): void
    {
        $this->createAd();
        AdService::flush();
        app()->forgetInstance(AdService::class);

        $html = view('components.ui.ad-slot', ['position' => 'home_billboard'])->render();

        $this->assertStringContainsString('srcset=', $html);
        $this->assertStringContainsString('.webp', $html);

        // The box is max-width 970px with the image at w-full, so the rendered
        // width is 970 until the viewport is narrower and 100vw after.
        $this->assertStringContainsString('sizes="(max-width: 970px) 100vw, 970px"', $html);
    }

    /**
     * A creative that predates the media library, or an external URL, has no
     * row and therefore no ladder. It must still render — and must not emit a
     * one-candidate `srcset`, which tells the browser that width is the only
     * option and is worse than none.
     */
    public function test_an_untracked_creative_still_renders_without_a_srcset(): void
    {
        Ad::create([
            'title' => 'পুরোনো ক্রিয়েটিভ',
            'position' => 'home_billboard',
            'type' => 'image',
            'asset' => 'ads/qSLsw9NEQSNOoRiqDEC7DAL5Zp3PYprJZV4pV9K3.jpg',
            'is_active' => true,
        ]);
        AdService::flush();
        app()->forgetInstance(AdService::class);

        $html = view('components.ui.ad-slot', ['position' => 'home_billboard'])->render();

        $this->assertStringContainsString('qSLsw9NEQSNOoRiqDEC7DAL5Zp3PYprJZV4pV9K3.jpg', $html);
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringNotContainsString('sizes=', $html);
    }

    /**
     * `ImageService` does not upscale, so a slot-sized creative produces a
     * one-rung ladder — which is the *common* case for the 300px and 336px
     * slots, not an edge one.
     *
     * It is still offered. The usual advice against a single-candidate srcset
     * is about stopping a browser reaching for something larger, and there is
     * nothing larger here; what there is, is WebP where the fallback is JPEG.
     * Pinned with the byte comparison so nobody "fixes" this into serving the
     * original again.
     */
    public function test_a_slot_sized_creative_still_gets_its_single_webp_rung(): void
    {
        $this->createAd([
            'position' => 'sidebar_rectangle',
            'file' => $this->upload('small.jpg', 300, 250),
        ]);
        AdService::flush();
        app()->forgetInstance(AdService::class);

        $ad = app(AdService::class)->for('sidebar_rectangle');

        $this->assertNotNull($ad->creative_srcset);
        $this->assertSame(1, substr_count($ad->creative_srcset, 'w,') + 1, 'Expected exactly one rung.');
        $this->assertStringContainsString('.webp', $ad->creative_srcset);

        $media = $ad->creative;
        $rung = collect($media->conversions)->first(fn ($p, $k) => str_starts_with($k, 'w'));

        $this->assertLessThan(
            Storage::disk($media->disk)->size($media->path),
            Storage::disk($media->disk)->size($rung),
            'The rung is not smaller than the original, so offering it buys nothing.'
        );
    }

    // ── The three ways this change could break something else ────────────

    /**
     * `creative_srcset` reads a relation, so it needs the same
     * `relationLoaded()` guard `Article::imageSrcset` has: a caller that did
     * not eager-load gets null rather than a lazy load.
     *
     * Asserted against a **freshly queried** ad rather than by rendering the
     * page, and that distinction is the whole point. The slots on a page come
     * out of `ads.live`, and a model restored from the cache was never
     * hydrated by the query builder — `unserialize()` fires no `retrieved`
     * event, so `AppServiceProvider::closeTheLazyLoadingHole()` never stamps
     * it and strict mode cannot see a lazy load there at all. A test that
     * loaded the homepage and asserted 200 would pass with the guard removed
     * *and* the eager load removed. This one does not.
     */
    public function test_the_srcset_accessor_refuses_to_lazy_load(): void
    {
        $this->createAd();

        $ad = Ad::query()->firstOrFail();

        $this->assertFalse($ad->relationLoaded('creative'));
        $this->assertNull($ad->creative_srcset, 'The accessor reached for a relation nobody loaded.');
    }

    public function test_a_page_full_of_slots_renders(): void
    {
        $this->createAd();
        AdService::flush();
        app()->forgetInstance(AdService::class);

        // The homepage carries several slots and the layout carries more.
        $this->get('/')->assertOk();
    }

    /**
     * The payload now holds `Media` as well as `Ad`. A class missing from
     * `config/cache.php` → `serializable_classes` is a TypeError on the *next*
     * request, not the one that wrote it — which is exactly the shape of bug
     * that reaches production.
     */
    public function test_the_cached_payload_round_trips_through_the_store(): void
    {
        $ad = $this->createAd();

        $service = app(AdService::class);
        $service->all();                       // writes the cache

        app()->forgetInstance(AdService::class);

        $again = app(AdService::class)->for('home_billboard');

        $this->assertNotNull($again);
        $this->assertSame($ad->id, $again->id);
        $this->assertTrue($again->relationLoaded('creative'), 'The relation did not survive the cache.');
        $this->assertNotNull($again->creative_srcset, 'The ladder did not survive the cache.');
    }

    public function test_a_page_of_slots_still_costs_one_query(): void
    {
        $this->createAd();
        $this->createAd(['position' => 'sidebar_rectangle']);
        AdService::flush();
        app()->forgetInstance(AdService::class);

        $service = app(AdService::class);

        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $service->for('home_billboard');
        $service->for('sidebar_rectangle');
        $service->for('in_article');

        // One for the cache read, one for the ads, one for the eager load —
        // and crucially not one per slot.
        $this->assertLessThanOrEqual(3, $queries, 'Slots are being resolved one query at a time.');
    }

    /** CLS is zero because the box is reserved. srcset must not cost that. */
    public function test_the_slot_keeps_its_reserved_dimensions(): void
    {
        $this->createAd();
        AdService::flush();
        app()->forgetInstance(AdService::class);

        $html = view('components.ui.ad-slot', ['position' => 'home_billboard'])->render();

        $this->assertStringContainsString('width="970"', $html);
        $this->assertStringContainsString('height="250"', $html);
        $this->assertStringContainsString('aspect-ratio: 970/250', $html);
    }

    /**
     * Replacing a creative reaps the old file. The new link has to move with
     * it, or the ad points at a media row whose file has gone.
     */
    public function test_replacing_a_creative_moves_the_link(): void
    {
        $ad = $this->createAd();
        $first = $ad->media_id;

        $this->actingAs($this->admin())->put("/admin/ads/{$ad->id}", [
            'title' => 'ঈদ অফার',
            'position' => 'home_billboard',
            'type' => 'image',
            'is_active' => '1',
            'file' => $this->upload('replacement.jpg'),
        ])->assertRedirect();

        $ad->refresh();

        $this->assertNotSame($first, $ad->media_id);
        $this->assertSame($ad->asset, Media::findOrFail($ad->media_id)->path);
    }

    /**
     * `nullOnDelete`, because a media row can be shared. Reaping it must blank
     * the link rather than take the ad with it.
     */
    public function test_deleting_the_media_row_blanks_the_link_rather_than_the_ad(): void
    {
        $ad = $this->createAd();

        Media::whereKey($ad->media_id)->delete();

        $this->assertDatabaseHas('ads', ['id' => $ad->id]);
        $this->assertNull($ad->fresh()->media_id);
    }
}
