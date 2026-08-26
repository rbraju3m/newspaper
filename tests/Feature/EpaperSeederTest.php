<?php

namespace Tests\Feature;

use App\Models\Epaper;
use Database\Seeders\EpaperSeeder;
use Database\Seeders\Support\SeedImagery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `EpaperSeeder` — the demo back issues behind `/epaper`.
 *
 * Drawing a page is expensive, so the tests here are shaped to draw as few as
 * possible: the seeder covers six dates, and pre-creating five of them leaves
 * exactly one issue's worth of work. The skip path costs nothing at all, which
 * is the point of it.
 */
class EpaperSeederTest extends TestCase
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

    /** Occupies every date the seeder covers except the one given. */
    private function occupyAllBut(int $leaveDaysAgo): void
    {
        foreach (range(0, 5) as $daysAgo) {
            if ($daysAgo === $leaveDaysAgo) {
                continue;
            }

            Epaper::create([
                'date' => now()->subDays($daysAgo)->toDateString(),
                'edition' => 'main',
                'is_published' => true,
            ]);
        }
    }

    public function test_it_draws_a_complete_issue(): void
    {
        $this->occupyAllBut(3);
        $date = now()->subDays(3)->toDateString();

        $this->seed(EpaperSeeder::class);

        $epaper = Epaper::where('date', $date)->sole();
        $pages = $epaper->pages()->orderBy('page_number')->get();

        $this->assertCount(8, $pages);
        $this->assertSame(range(1, 8), $pages->pluck('page_number')->map('intval')->all());
        $this->assertTrue($epaper->is_published);
        $this->assertSame(8, (int) $epaper->pages_count, 'The denormalised count must be right straight out of the seeder.');

        foreach ($pages as $page) {
            Storage::disk('public')->assertExists($page->image);
            $this->assertNotNull($page->thumbnail);
            Storage::disk('public')->assertExists($page->thumbnail);
            $this->assertNotNull($page->section);
        }

        $this->assertSame($pages->first()->thumbnail, $epaper->cover);
    }

    public function test_it_skips_dates_that_already_have_an_issue(): void
    {
        $this->occupyAllBut(-1);      // -1 is not in range, so all six exist

        $this->seed(EpaperSeeder::class);

        $this->assertSame(6, Epaper::count());
        $this->assertSame(0, \App\Models\EpaperPage::count(), 'Re-running must not redraw a paper that is already there.');
    }

    /**
     * An issue somebody made through the admin sits on one of the seeder's
     * dates. It must be left exactly as it is, pages and all.
     */
    public function test_it_leaves_a_hand_made_issue_alone(): void
    {
        $this->occupyAllBut(2);

        $mine = Epaper::create([
            'date' => now()->subDays(2)->toDateString(),
            'edition' => 'main',
            'is_published' => false,
        ]);

        $mine->pages()->create(['page_number' => 1, 'image' => 'uploads/mine/front.jpg']);

        $this->seed(EpaperSeeder::class);

        $mine->refresh();

        $this->assertSame(1, $mine->pages()->count());
        $this->assertSame('uploads/mine/front.jpg', $mine->pages()->sole()->image);
        $this->assertFalse($mine->is_published);
    }

    public function test_a_drawn_page_is_a_portrait_broadsheet(): void
    {
        $imagery = new SeedImagery(crc32('test'));

        $front = $imagery->newspaperPage('#C8102E', 400, 560, front: true);
        $inside = $imagery->newspaperPage('#1B7F4B', 400, 560);

        foreach ([$front, $inside] as $page) {
            $this->assertSame(400, imagesx($page));
            $this->assertSame(560, imagesy($page));
            imagedestroy($page);
        }
    }

    public function test_the_same_date_always_draws_the_same_paper(): void
    {
        // Determinism is what makes a redraw reproduce the demo rather than
        // quietly change every thumbnail on the site.
        $a = (new SeedImagery(crc32('2026-08-25-1')))->newspaperPage('#C8102E', 200, 280, front: true);
        $b = (new SeedImagery(crc32('2026-08-25-1')))->newspaperPage('#C8102E', 200, 280, front: true);

        ob_start();
        imagepng($a);
        $first = ob_get_clean();

        ob_start();
        imagepng($b);
        $second = ob_get_clean();

        imagedestroy($a);
        imagedestroy($b);

        $this->assertSame($first, $second);
    }
}
