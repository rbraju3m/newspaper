<?php

namespace Tests\Feature;

use App\Models\Epaper;
use App\Models\EpaperPage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The e-paper admin — issues, their page images and the whole-issue PDF.
 *
 * The sharp edge here is `unique(epaper_id, page_number)`. Any reordering that
 * writes the new numbers straight out collides the moment two pages swap:
 * setting page 2 to 1 while a page 1 still exists is a duplicate key, not a
 * reordering. Every test that moves a page is really a test of that.
 */
class EpaperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function editor(): User
    {
        return User::factory()->editor()->create()->fresh();
    }

    private function issue(array $attributes = []): Epaper
    {
        return Epaper::create([
            'date' => now()->toDateString(),
            'edition' => 'main',
            'is_published' => true,
            ...$attributes,
        ]);
    }

    private function page(Epaper $epaper, int $number): EpaperPage
    {
        return $epaper->pages()->create([
            'page_number' => $number,
            'image' => 'uploads/test/p'.$number.'.jpg',
            'thumbnail' => 'uploads/test/p'.$number.'-thumb.webp',
        ]);
    }

    private function upload(string $name = 'page.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 1600);
    }

    /** @return list<int> page ids in page_number order, read from the rows */
    private function order(Epaper $epaper): array
    {
        return DB::table('epaper_pages')->where('epaper_id', $epaper->id)
            ->orderBy('page_number')->pluck('id')->map('intval')->all();
    }

    // ── Issues ───────────────────────────────────────────────────────────

    public function test_an_editor_creates_an_issue(): void
    {
        $this->actingAs($this->editor())
            ->post('/admin/epapers', [
                'date' => '2026-08-27',
                'edition' => 'dhaka',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $epaper = Epaper::sole();

        $this->assertSame('2026-08-27', $epaper->date->toDateString());
        $this->assertSame('dhaka', $epaper->edition);
        $this->assertFalse($epaper->is_published, 'An issue is not published until somebody says so.');
    }

    public function test_one_issue_per_edition_per_day(): void
    {
        $this->issue(['date' => '2026-08-27', 'edition' => 'main']);

        $this->actingAs($this->editor())
            ->post('/admin/epapers', ['date' => '2026-08-27', 'edition' => 'main'])
            ->assertSessionHasErrors(['date' => 'এই তারিখে এই সংস্করণের সংখ্যা ইতিমধ্যেই আছে।']);

        $this->assertSame(1, Epaper::count());
    }

    public function test_a_second_edition_on_the_same_day_is_fine(): void
    {
        $this->issue(['date' => '2026-08-27', 'edition' => 'main']);

        $this->actingAs($this->editor())
            ->post('/admin/epapers', ['date' => '2026-08-27', 'edition' => 'dhaka'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Epaper::count());
    }

    public function test_an_issue_can_keep_its_own_date_when_edited(): void
    {
        $epaper = $this->issue(['date' => '2026-08-27']);

        $this->actingAs($this->editor())
            ->put('/admin/epapers/'.$epaper->id, [
                'date' => '2026-08-27',
                'edition' => 'main',
                'is_published' => '1',
            ])->assertSessionHasNoErrors();

        $this->assertTrue($epaper->fresh()->is_published);
    }

    // ── Pages ────────────────────────────────────────────────────────────

    public function test_uploading_pages_numbers_them_in_order_and_builds_thumbnails(): void
    {
        $epaper = $this->issue();

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pages', [
                'files' => [$this->upload('a.jpg'), $this->upload('b.jpg'), $this->upload('c.jpg')],
                'section' => 'প্রথম পাতা',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $pages = $epaper->pages()->orderBy('page_number')->get();

        $this->assertCount(3, $pages);
        $this->assertSame([1, 2, 3], $pages->pluck('page_number')->map('intval')->all());

        foreach ($pages as $page) {
            $this->assertSame('প্রথম পাতা', $page->section);
            Storage::disk('public')->assertExists($page->image);
            $this->assertNotNull($page->thumbnail, 'The grid would otherwise load full broadsheets.');
            Storage::disk('public')->assertExists($page->thumbnail);
        }
    }

    public function test_a_second_upload_appends_rather_than_restarting(): void
    {
        $epaper = $this->issue();
        $editor = $this->editor();

        $this->actingAs($editor)->post('/admin/epapers/'.$epaper->id.'/pages', ['files' => [$this->upload()]]);
        $this->actingAs($editor)->post('/admin/epapers/'.$epaper->id.'/pages', ['files' => [$this->upload()]]);

        $this->assertSame([1, 2], $epaper->pages()->orderBy('page_number')->pluck('page_number')->map('intval')->all());
    }

    public function test_page_one_becomes_the_cover(): void
    {
        $epaper = $this->issue();

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pages', ['files' => [$this->upload()]]);

        $epaper->refresh();
        $first = $epaper->pages()->orderBy('page_number')->first();

        $this->assertSame($first->thumbnail, $epaper->cover);
    }

    public function test_a_pdf_can_be_attached_and_replaced(): void
    {
        $epaper = $this->issue();
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post('/admin/epapers/'.$epaper->id.'/pdf', [
                'pdf' => UploadedFile::fake()->create('issue.pdf', 200, 'application/pdf'),
            ])->assertSessionHasNoErrors();

        $first = $epaper->fresh()->pdf;

        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($editor)
            ->post('/admin/epapers/'.$epaper->id.'/pdf', [
                'pdf' => UploadedFile::fake()->create('corrected.pdf', 200, 'application/pdf'),
            ])->assertSessionHasNoErrors();

        $second = $epaper->fresh()->pdf;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first, 'The replaced PDF was left on disk.');
    }

    public function test_only_pdfs_are_accepted_as_the_issue_download(): void
    {
        $epaper = $this->issue();

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pdf', ['pdf' => $this->upload('not-a-pdf.jpg')])
            ->assertSessionHasErrors('pdf');

        $this->assertNull($epaper->fresh()->pdf);
    }

    // ── Renumbering: the unique constraint ───────────────────────────────

    public function test_swapping_two_pages_does_not_collide(): void
    {
        $epaper = $this->issue();
        $one = $this->page($epaper, 1);
        $two = $this->page($epaper, 2);

        // The straight-through write would set page 2 to 1 while page 1 still
        // holds it — a duplicate key on (epaper_id, page_number).
        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pages/reorder', ['pages' => [$two->id, $one->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame([$two->id, $one->id], $this->order($epaper));
        $this->assertSame(1, (int) $two->fresh()->page_number);
        $this->assertSame(2, (int) $one->fresh()->page_number);
    }

    public function test_a_full_reversal_renumbers_cleanly(): void
    {
        $epaper = $this->issue();
        $pages = collect(range(1, 8))->map(fn ($n) => $this->page($epaper, $n));

        $reversed = $pages->pluck('id')->reverse()->values()->all();

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pages/reorder', ['pages' => $reversed])
            ->assertSessionHasNoErrors();

        $this->assertSame($reversed, $this->order($epaper));
        $this->assertSame(
            range(1, 8),
            DB::table('epaper_pages')->where('epaper_id', $epaper->id)
                ->orderBy('page_number')->pluck('page_number')->map('intval')->all(),
            'Page numbers must come out contiguous, not parked in the scratch band.'
        );
    }

    public function test_reordering_moves_the_cover(): void
    {
        $epaper = $this->issue();
        $one = $this->page($epaper, 1);
        $two = $this->page($epaper, 2);

        $epaper->forceFill(['cover' => $one->thumbnail])->save();

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pages/reorder', ['pages' => [$two->id, $one->id]]);

        $this->assertSame($two->thumbnail, $epaper->fresh()->cover);
    }

    public function test_a_reorder_cannot_graft_in_another_issues_page(): void
    {
        $mine = $this->issue(['date' => '2026-08-26']);
        $theirs = $this->issue(['date' => '2026-08-25']);

        $ours = $this->page($mine, 1);
        $stranger = $this->page($theirs, 1);

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$mine->id.'/pages/reorder', ['pages' => [$stranger->id, $ours->id]])
            ->assertSessionHasErrors('pages.0');

        $this->assertSame($theirs->id, $stranger->fresh()->epaper_id);
    }

    public function test_removing_a_page_closes_the_gap(): void
    {
        $epaper = $this->issue();
        $one = $this->page($epaper, 1);
        $two = $this->page($epaper, 2);
        $three = $this->page($epaper, 3);

        $this->actingAs($this->editor())
            ->delete('/admin/epapers/pages/'.$two->id)
            ->assertSessionHasNoErrors();

        $this->assertSame([$one->id, $three->id], $this->order($epaper));
        $this->assertSame(2, (int) $three->fresh()->page_number, 'A hole in the numbering makes the next upload collide.');
    }

    // ── Deleting, and the files behind it ────────────────────────────────

    public function test_deleting_an_issue_takes_its_files_off_disk(): void
    {
        $epaper = $this->issue();
        $editor = $this->editor();

        $this->actingAs($editor)->post('/admin/epapers/'.$epaper->id.'/pages', ['files' => [$this->upload()]]);
        $this->actingAs($editor)->post('/admin/epapers/'.$epaper->id.'/pdf', [
            'pdf' => UploadedFile::fake()->create('issue.pdf', 100, 'application/pdf'),
        ]);

        $epaper->refresh();
        $page = $epaper->pages()->sole();
        $paths = array_filter([$epaper->pdf, $page->image, $page->thumbnail]);

        $this->actingAs($editor)->delete('/admin/epapers/'.$epaper->id)->assertRedirect();

        $this->assertSame(0, Epaper::count());
        $this->assertSame(0, EpaperPage::count());

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertSame(0, Media::count(), 'The media rows outlived the issue that owned them.');
    }

    public function test_removing_one_page_takes_its_file_too(): void
    {
        $epaper = $this->issue();

        $this->actingAs($this->editor())
            ->post('/admin/epapers/'.$epaper->id.'/pages', ['files' => [$this->upload()]]);

        $page = $epaper->pages()->sole();
        $paths = array_filter([$page->image, $page->thumbnail]);

        $this->actingAs($this->editor())->delete('/admin/epapers/pages/'.$page->id);

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    // ── The denormalised count ───────────────────────────────────────────

    public function test_pages_count_tracks_the_rows(): void
    {
        $epaper = $this->issue();

        $countFor = fn () => (int) DB::table('epapers')->where('id', $epaper->id)->value('pages_count');

        $this->assertSame(0, $countFor());

        $one = $this->page($epaper, 1);
        $this->page($epaper, 2);

        $this->assertSame(2, $countFor());

        $one->delete();

        $this->assertSame(1, $countFor());
    }

    public function test_counters_recompute_corrects_drift(): void
    {
        $epaper = $this->issue();
        $this->page($epaper, 1);

        DB::table('epapers')->where('id', $epaper->id)->update(['pages_count' => 42]);

        $this->artisan('counters:recompute')->assertSuccessful();

        $this->assertSame(1, (int) DB::table('epapers')->where('id', $epaper->id)->value('pages_count'));
    }

    // ── Authorisation ────────────────────────────────────────────────────

    public function test_a_reporter_cannot_touch_the_epaper(): void
    {
        $epaper = $this->issue();
        $reporter = User::factory()->reporter()->create()->fresh();

        $this->actingAs($reporter)->get('/admin/epapers')->assertForbidden();
        $this->actingAs($reporter)->post('/admin/epapers', ['date' => '2026-01-01', 'edition' => 'main'])->assertForbidden();
        $this->actingAs($reporter)->get('/admin/epapers/'.$epaper->id.'/edit')->assertForbidden();
        $this->actingAs($reporter)->delete('/admin/epapers/'.$epaper->id)->assertForbidden();

        $this->assertSame(1, Epaper::count());
    }

    public function test_a_reader_gets_a_404(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->get('/admin/epapers')
            ->assertNotFound();
    }

    // ── The public reader ────────────────────────────────────────────────

    public function test_a_published_issue_reaches_the_reader(): void
    {
        $epaper = $this->issue(['date' => '2026-08-20', 'is_published' => true]);
        $this->page($epaper, 1);

        $this->get('/epaper/2026-08-20')->assertOk();
        $this->get('/epaper')->assertOk();
    }

    public function test_an_unpublished_issue_does_not(): void
    {
        $epaper = $this->issue(['date' => '2026-08-19', 'is_published' => false]);
        $this->page($epaper, 1);

        $this->get('/epaper/2026-08-19')->assertNotFound();
    }
}
