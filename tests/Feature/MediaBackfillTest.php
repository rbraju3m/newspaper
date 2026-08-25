<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `media:backfill` — bringing stored uploads forward after a WIDTHS change.
 *
 * The bug this guards against is silence: adding a rung to ImageService changes
 * nothing about rows already in the media table, because srcset is built from
 * the `conversions` each row recorded. Everything keeps working and every image
 * keeps serving the old ladder, with nothing to indicate it.
 */
class MediaBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** Writes a real JPEG to the faked disk and records it, minus some rungs. */
    private function storedImage(array $conversions, int $width = 2400, int $height = 1350): Media
    {
        $path = 'uploads/test/source.jpg';

        // path() resolves a location; it does not create the directory, and GD
        // writes straight to the filesystem rather than through the disk.
        Storage::disk('public')->makeDirectory('uploads/test');

        $im = imagecreatetruecolor($width, $height);
        imagefilledrectangle($im, 0, 0, $width, $height, imagecolorallocate($im, 40, 90, 140));
        imagejpeg($im, Storage::disk('public')->path($path), 80);
        imagedestroy($im);

        return Media::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'mime' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'conversions' => $conversions,
        ]);
    }

    public function test_it_rebuilds_a_ladder_that_is_missing_a_rung(): void
    {
        Storage::fake('public');

        $media = $this->storedImage([
            'w320' => 'uploads/test/source-w320.webp',
            'w640' => 'uploads/test/source-w640.webp',
            'thumb' => 'uploads/test/source-thumb.webp',
        ]);

        $this->artisan('media:backfill')->assertSuccessful();

        $rungs = array_keys($media->fresh()->conversions);

        foreach (['w320', 'w640', 'w768', 'w960', 'w1600', 'thumb'] as $key) {
            $this->assertContains($key, $rungs);
        }

        // The derivatives are on disk, not merely named in the row — the whole
        // failure mode here is a row that claims a rung it cannot serve.
        Storage::disk('public')->assertExists('uploads/test/source-w768.webp');
    }

    public function test_it_leaves_a_current_ladder_untouched(): void
    {
        Storage::fake('public');

        // rungsFor() returns a list of keys, so the ladder has to be built with
        // array_fill_keys — `+ ['thumb' => ...]` on the raw list leaves numeric
        // keys and quietly describes a row with no rungs at all.
        $current = array_fill_keys(app(ImageService::class)->rungsFor(2400), 'uploads/test/x.webp');
        $current['thumb'] = 'uploads/test/x-thumb.webp';

        $media = $this->storedImage($current);

        $this->artisan('media:backfill')->assertSuccessful();

        // Asserting on updated_at would only be accurate to the second, and a
        // rebuild that finishes inside the same second would pass. The absence
        // of the derivative on disk is the deterministic signal.
        // assertEquals, not assertSame: MySQL reorders the keys of a JSON
        // column on the way back out, so identity would fail on ordering alone
        // while every key and value matched.
        $this->assertEquals($current, $media->fresh()->conversions);
        Storage::disk('public')->assertMissing('uploads/test/source-w768.webp');
    }

    public function test_dry_run_writes_nothing(): void
    {
        Storage::fake('public');

        $media = $this->storedImage(['w320' => 'uploads/test/source-w320.webp']);

        $this->artisan('media:backfill', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(['w320'], array_keys($media->fresh()->conversions));
        Storage::disk('public')->assertMissing('uploads/test/source-w768.webp');
    }

    public function test_it_reports_rows_whose_file_is_gone_rather_than_failing(): void
    {
        Storage::fake('public');

        // Exactly what an ad creative replaced through the admin leaves behind:
        // AdController deletes the file by path without asking whether a Media
        // row owns it.
        Media::factory()->create([
            'disk' => 'public',
            'path' => 'uploads/test/deleted.jpg',
            'mime' => 'image/jpeg',
        ]);

        $this->artisan('media:backfill')
            ->expectsOutputToContain('reference a file that is gone')
            ->assertSuccessful();
    }

    public function test_it_skips_rows_it_could_never_derive_from(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('uploads/test/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $media = Media::factory()->create([
            'disk' => 'public',
            'path' => 'uploads/test/logo.svg',
            'mime' => 'image/svg+xml',
            'conversions' => [],
        ]);

        $this->artisan('media:backfill')->assertSuccessful();

        // An SVG has no ladder to be behind on, so it must not be counted as a
        // failure or repeatedly retried on every run.
        $this->assertSame([], $media->fresh()->conversions);
        $this->assertTrue(app(ImageService::class)->hasCurrentLadder($media->fresh()));
    }

    public function test_has_current_ladder_requires_the_thumbnail_too(): void
    {
        $images = app(ImageService::class);

        $full = Media::factory()->make(['width' => 2400]);
        $this->assertTrue($images->hasCurrentLadder($full));

        $noThumb = Media::factory()->make([
            'width' => 2400,
            'conversions' => array_diff_key($full->conversions, ['thumb' => null]),
        ]);
        $this->assertFalse($images->hasCurrentLadder($noThumb));
    }
}
