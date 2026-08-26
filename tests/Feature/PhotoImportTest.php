<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `photos:import` — putting real photographs into the media library.
 *
 * The two things worth pinning are the ones a successful-looking run hides.
 * The source is transcoded rather than copied, because ImageService keeps what
 * it is handed as the stored original and that original is the plain `src`
 * behind every responsive image — a 2 MB PNG copied straight in serves 2 MB to
 * anything without WebP. And it is idempotent by `filename`, so a second run
 * relinks instead of filling the disk with a duplicate library.
 */
class PhotoImportTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->source = storage_path('framework/testing/photo-import-'.getmypid());
        File::ensureDirectoryExists($this->source);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    /** A PNG with a transparent corner, so flattening is observable. */
    private function png(string $name, int $width = 1200, int $height = 800): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $width, $height, imagecolorallocate($im, 30, 120, 60));
        imagefilledrectangle($im, 0, 0, 40, 40, imagecolorallocatealpha($im, 0, 0, 0, 127));

        $path = $this->source.'/'.$name;
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function test_it_imports_a_folder_and_builds_the_ladder(): void
    {
        $this->png('1.png');
        $this->png('2.png');

        $this->artisan('photos:import', [
            'directory' => $this->source,
            '--credit' => 'ছবি: প্রথম আলো',
        ])->assertSuccessful();

        $this->assertSame(2, Media::query()->count());

        $media = Media::query()->where('filename', '1.jpg')->sole();

        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame('ছবি: প্রথম আলো', $media->credit);
        $this->assertStringContainsString('/photos/', $media->path);

        Storage::disk('public')->assertExists($media->path);

        // The source is 1200 wide, so the ladder stops below w1600 rather than
        // upscaling — the rungs present are the ones the source can carry.
        $this->assertEqualsCanonicalizing(
            ['w320', 'w640', 'w768', 'w960', 'thumb'],
            array_keys($media->conversions)
        );

        foreach ($media->conversions as $rung) {
            Storage::disk('public')->assertExists($rung['path'] ?? $rung);
        }
    }

    public function test_the_stored_original_is_a_transcoded_jpeg_not_the_png(): void
    {
        $png = $this->png('big.png');

        $this->artisan('photos:import', ['directory' => $this->source])->assertSuccessful();

        $media = Media::query()->sole();

        // Asserted on type and bytes rather than on being smaller than the
        // source: whether JPEG beats PNG depends entirely on the content —
        // it does on a photograph (the 99 Prothom Alo frames went 2 MB → ~200 KB)
        // and does not on a flat plate. What must hold for any input is that
        // the original was re-encoded rather than copied through.
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertStringEndsWith('.jpg', $media->path);

        $stored = Storage::disk('public')->path($media->path);

        $this->assertNotSame(file_get_contents($png), file_get_contents($stored));
        $this->assertNotFalse(
            @imagecreatefromjpeg($stored),
            'The stored original must decode as JPEG.'
        );
    }

    public function test_transparency_is_flattened_onto_white_not_black(): void
    {
        $this->png('alpha.png');

        $this->artisan('photos:import', ['directory' => $this->source])->assertSuccessful();

        $media = Media::query()->sole();

        $im = imagecreatefromjpeg(Storage::disk('public')->path($media->path));
        $corner = imagecolorsforindex($im, imagecolorat($im, 5, 5));
        imagedestroy($im);

        // JPEG is lossy, so this asserts "light" rather than exactly white.
        $this->assertGreaterThan(
            200,
            min($corner['red'], $corner['green'], $corner['blue']),
            'A transparent corner written straight to JPEG comes out black.'
        );
    }

    public function test_a_second_run_relinks_rather_than_duplicating(): void
    {
        $this->png('1.png');

        $this->artisan('photos:import', ['directory' => $this->source])->assertSuccessful();
        $first = Media::query()->sole();

        $this->artisan('photos:import', ['directory' => $this->source])
            ->expectsOutputToContain('Already in the library')
            ->assertSuccessful();

        $this->assertSame(1, Media::query()->count());
        $this->assertSame($first->id, Media::query()->sole()->id);
    }

    public function test_assign_links_every_article_deterministically(): void
    {
        $this->png('1.png');
        $this->png('2.png');

        $articles = Article::factory()->count(5)->create();

        $this->artisan('photos:import', [
            'directory' => $this->source,
            '--assign' => true,
            '--credit' => 'ছবি: প্রথম আলো',
        ])->assertSuccessful();

        $this->assertSame(0, Article::query()->whereNull('image_id')->count());

        foreach ($articles as $article) {
            $article->refresh();

            $media = Media::query()->findOrFail($article->image_id);

            // Both columns: image_id feeds the srcset, image feeds the plain src.
            $this->assertSame($media->path, $article->image);
            $this->assertSame('ছবি: প্রথম আলো', $article->image_credit);
        }

        $before = Article::query()->orderBy('id')->pluck('image_id')->all();

        $this->artisan('photos:import', [
            'directory' => $this->source,
            '--assign' => true,
        ])->assertSuccessful();

        $this->assertSame(
            $before,
            Article::query()->orderBy('id')->pluck('image_id')->all(),
            'Re-running must not reshuffle the front page.'
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->png('1.png');
        $article = Article::factory()->create(['image_id' => null]);

        $this->artisan('photos:import', [
            'directory' => $this->source,
            '--assign' => true,
            '--dry-run' => true,
        ])->expectsOutputToContain('Would link')->assertSuccessful();

        $this->assertSame(0, Media::query()->count());
        $this->assertNull($article->fresh()->image_id);
    }

    public function test_a_missing_directory_fails_rather_than_reporting_success(): void
    {
        $this->artisan('photos:import', ['directory' => $this->source.'/nope'])
            ->assertFailed();
    }

    public function test_non_images_are_ignored(): void
    {
        $this->png('1.png');
        File::put($this->source.'/notes.txt', 'not an image');
        File::put($this->source.'/archive.zip', 'PK');

        $this->artisan('photos:import', ['directory' => $this->source])->assertSuccessful();

        $this->assertSame(1, Media::query()->count());
    }
}
