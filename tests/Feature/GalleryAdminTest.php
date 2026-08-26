<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Gallery;
use App\Models\GalleryImage;
use App\Models\Media;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The photo gallery admin — the module `/photo` had been rendering an empty hub
 * for, because there was no way to create one short of INSERT.
 *
 * Two things here are easy to get subtly wrong and quiet when they are. Each
 * image carries both a `media_id` and a denormalised `path`, and writing only
 * the first leaves the plain `src` — the fallback for anything without WebP —
 * pointing at nothing. And `galleries.cover` is a bare path from before the
 * media library existed, so it dangles the moment the image it names is
 * removed or dragged out of first place.
 */
class GalleryAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function editor(): User
    {
        // ->fresh(): a factory-built model holds only what the factory set, and
        // strict mode throws on reading anything it did not.
        return User::factory()->editor()->create()->fresh();
    }

    private function upload(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 800);
    }

    /** An image row plus the media row behind it, as the controller writes them. */
    private function imageOn(Gallery $gallery, int $position = 0): GalleryImage
    {
        $media = Media::factory()->create([
            'disk' => 'public',
            'path' => 'uploads/test/'.$position.'.jpg',
            'mime' => 'image/jpeg',
        ]);

        return $gallery->images()->create([
            'media_id' => $media->id,
            'path' => $media->path,
            'position' => $position,
        ]);
    }

    // ── The gallery itself ───────────────────────────────────────────────

    public function test_an_editor_creates_a_gallery_and_lands_on_its_edit_screen(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->editor())
            ->post('/admin/galleries', [
                'title' => 'বর্ষার ঢাকা',
                'description' => 'বৃষ্টিভেজা শহরের ছবি।',
                'category_id' => $category->id,
                'status' => 'draft',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $gallery = Gallery::sole();

        $this->assertSame('বর্ষার ঢাকা', $gallery->title);
        $this->assertSame($category->id, $gallery->category_id);
        $this->assertSame('draft', $gallery->status);
        $this->assertNull($gallery->published_at);
    }

    public function test_the_slug_is_generated_in_bangla_and_made_unique(): void
    {
        $editor = $this->editor();

        foreach (['ক্রিকেট বিশ্বকাপ', 'ক্রিকেট বিশ্বকাপ'] as $title) {
            $this->actingAs($editor)->post('/admin/galleries', [
                'title' => $title,
                'status' => 'draft',
            ])->assertSessionHasNoErrors();
        }

        $slugs = Gallery::orderBy('id')->pluck('slug')->all();

        // \p{M} kept: without it ক্রিকেট collapses to করকট.
        $this->assertSame(['ক্রিকেট-বিশ্বকাপ', 'ক্রিকেট-বিশ্বকাপ-2'], $slugs);
    }

    public function test_publishing_without_a_time_stamps_it_now(): void
    {
        $gallery = Gallery::factory()->draft()->create();

        $this->actingAs($this->editor())
            ->put('/admin/galleries/'.$gallery->id, [
                'title' => $gallery->title,
                'status' => 'published',
            ])->assertSessionHasNoErrors();

        $this->assertNotNull($gallery->fresh()->published_at);
    }

    public function test_an_editors_chosen_time_survives(): void
    {
        $gallery = Gallery::factory()->draft()->create();
        $when = now()->addDay()->startOfMinute();

        $this->actingAs($this->editor())
            ->put('/admin/galleries/'.$gallery->id, [
                'title' => $gallery->title,
                'status' => 'published',
                'published_at' => $when->toDateTimeString(),
            ])->assertSessionHasNoErrors();

        $this->assertSame($when->toDateTimeString(), $gallery->fresh()->published_at->toDateTimeString());
    }

    public function test_a_title_is_required(): void
    {
        $this->actingAs($this->editor())
            ->post('/admin/galleries', ['status' => 'draft'])
            ->assertSessionHasErrors(['title' => 'গ্যালারির শিরোনাম লিখুন।']);

        $this->assertSame(0, Gallery::count());
    }

    // ── Images ───────────────────────────────────────────────────────────

    public function test_uploading_images_writes_both_columns_and_builds_the_ladder(): void
    {
        $gallery = Gallery::factory()->create();

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images', [
                'files' => [$this->upload('one.jpg'), $this->upload('two.jpg')],
                'credit' => 'ছবি: প্রথম আলো',
            ])->assertSessionHasNoErrors()->assertRedirect();

        $images = $gallery->images()->orderBy('position')->get();

        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('position')->map('intval')->all());

        foreach ($images as $image) {
            $this->assertNotNull($image->media_id, 'media_id is what builds the srcset.');
            $this->assertNotNull($image->path, 'path is what the plain src falls back to.');
            $this->assertSame('ছবি: প্রথম আলো', $image->credit);

            $media = Media::findOrFail($image->media_id);

            $this->assertSame($media->path, $image->path);
            Storage::disk('public')->assertExists($media->path);
            $this->assertNotEmpty($media->conversions);
        }
    }

    public function test_the_first_upload_becomes_the_cover(): void
    {
        $gallery = Gallery::factory()->create(['cover' => null]);

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images', ['files' => [$this->upload()]]);

        $gallery->refresh();

        $this->assertNotNull($gallery->cover);
        $this->assertSame($gallery->images()->orderBy('position')->first()->path, $gallery->cover);
    }

    public function test_images_can_be_attached_from_the_library_without_reuploading(): void
    {
        $gallery = Gallery::factory()->create();

        $media = Media::factory()->count(2)->create([
            'disk' => 'public',
            'mime' => 'image/jpeg',
            'caption' => 'লাইব্রেরির ক্যাপশন',
        ]);

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images/attach', [
                'media' => $media->pluck('id')->all(),
            ])->assertSessionHasNoErrors();

        $this->assertSame(2, $gallery->images()->count());
        $this->assertSame('লাইব্রেরির ক্যাপশন', $gallery->images()->first()->caption);
    }

    public function test_attaching_the_same_image_twice_is_a_slip_not_an_error(): void
    {
        $gallery = Gallery::factory()->create();
        $media = Media::factory()->create(['disk' => 'public', 'mime' => 'image/jpeg']);

        $editor = $this->editor();

        $this->actingAs($editor)->post('/admin/galleries/'.$gallery->id.'/images/attach', ['media' => [$media->id]]);
        $this->actingAs($editor)->post('/admin/galleries/'.$gallery->id.'/images/attach', ['media' => [$media->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $gallery->images()->count());
    }

    public function test_a_caption_can_be_edited(): void
    {
        $gallery = Gallery::factory()->create();
        $image = $this->imageOn($gallery);

        $this->actingAs($this->editor())
            ->put('/admin/galleries/images/'.$image->id, [
                'caption' => 'নৌকায় হাঁসের সারি',
                'credit' => 'ছবি: প্রথম আলো',
            ])->assertSessionHasNoErrors();

        $this->assertSame('নৌকায় হাঁসের সারি', $image->fresh()->caption);
    }

    // ── Ordering, and the cover that follows it ──────────────────────────

    public function test_reordering_rewrites_positions_and_moves_the_cover(): void
    {
        $gallery = Gallery::factory()->create();

        $first = $this->imageOn($gallery, 0);
        $second = $this->imageOn($gallery, 1);
        $third = $this->imageOn($gallery, 2);

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images/reorder', [
                'images' => [$third->id, $first->id, $second->id],
            ])->assertSessionHasNoErrors();

        $order = DB::table('gallery_images')->where('gallery_id', $gallery->id)
            ->orderBy('position')->pluck('id')->all();

        $this->assertSame([$third->id, $first->id, $second->id], $order);
        $this->assertSame($third->path, $gallery->fresh()->cover, 'The first image is the cover.');
    }

    public function test_a_reorder_cannot_graft_in_another_gallerys_image(): void
    {
        $mine = Gallery::factory()->create();
        $theirs = Gallery::factory()->create();

        $ours = $this->imageOn($mine, 0);
        $stranger = $this->imageOn($theirs, 0);

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$mine->id.'/images/reorder', [
                'images' => [$stranger->id, $ours->id],
            ])->assertSessionHasErrors('images.0');

        $this->assertSame($theirs->id, $stranger->fresh()->gallery_id);
    }

    public function test_removing_the_cover_image_promotes_the_next(): void
    {
        $gallery = Gallery::factory()->create();

        $first = $this->imageOn($gallery, 0);
        $second = $this->imageOn($gallery, 1);

        $gallery->forceFill(['cover' => $first->path])->save();

        $this->actingAs($this->editor())
            ->delete('/admin/galleries/images/'.$first->id)
            ->assertSessionHasNoErrors();

        $this->assertSame($second->path, $gallery->fresh()->cover, 'A cover pointing at a deleted image is a broken thumbnail.');
    }

    public function test_removing_the_last_image_clears_the_cover(): void
    {
        $gallery = Gallery::factory()->create();
        $only = $this->imageOn($gallery, 0);
        $gallery->forceFill(['cover' => $only->path])->save();

        $this->actingAs($this->editor())->delete('/admin/galleries/images/'.$only->id);

        $this->assertNull($gallery->fresh()->cover);
    }

    // ── Deleting, and the files behind it ────────────────────────────────

    /**
     * `release()` is reference counted over `gallery_images.path` and
     * `galleries.cover` among others, so it has to be called *after* the rows
     * are gone. Called before, every file is still a reference to itself and
     * nothing is ever freed — which looks exactly like success, because the
     * rows do disappear. Only the disk says otherwise.
     */
    public function test_deleting_a_gallery_takes_its_files_off_disk(): void
    {
        $gallery = Gallery::factory()->create();

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images', ['files' => [$this->upload()]]);

        $media = Media::sole();
        $paths = array_merge([$media->path], array_map(
            fn ($c) => $c['path'] ?? $c,
            array_values($media->conversions ?? [])
        ));

        $this->actingAs($this->editor())->delete('/admin/galleries/'.$gallery->id)->assertRedirect();

        $this->assertSame(0, Gallery::count());
        $this->assertSame(0, GalleryImage::count());
        $this->assertSame(0, Media::count(), 'The media row outlived the gallery that owned it.');

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_removing_one_image_takes_its_file_too(): void
    {
        $gallery = Gallery::factory()->create();

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images', ['files' => [$this->upload()]]);

        $path = Media::sole()->path;

        $this->actingAs($this->editor())
            ->delete('/admin/galleries/images/'.$gallery->images()->sole()->id)
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
    }

    /**
     * The library is shared. A photograph doing duty as an article's lead image
     * must survive the gallery it also appeared in — `articles.image` is one of
     * the columns `release()` counts.
     */
    public function test_a_photograph_an_article_still_uses_is_not_reaped(): void
    {
        $gallery = Gallery::factory()->create();

        $this->actingAs($this->editor())
            ->post('/admin/galleries/'.$gallery->id.'/images', ['files' => [$this->upload()]]);

        $media = Media::sole();

        \App\Models\Article::factory()->create([
            'image_id' => $media->id,
            'image' => $media->path,
        ]);

        $this->actingAs($this->editor())->delete('/admin/galleries/'.$gallery->id);

        $this->assertSame(1, Media::count(), 'Reaping this would have blanked the article.');
        Storage::disk('public')->assertExists($media->path);
    }

    // ── The denormalised count ───────────────────────────────────────────

    public function test_images_count_tracks_the_rows(): void
    {
        $gallery = Gallery::factory()->create();

        $countFor = fn () => (int) DB::table('galleries')->where('id', $gallery->id)->value('images_count');

        $this->assertSame(0, $countFor());

        $first = $this->imageOn($gallery, 0);
        $this->imageOn($gallery, 1);

        $this->assertSame(2, $countFor());

        $first->delete();

        $this->assertSame(1, $countFor());
    }

    public function test_counters_recompute_corrects_drift(): void
    {
        $gallery = Gallery::factory()->create();
        $this->imageOn($gallery, 0);

        // A write that goes round Eloquent, which is exactly what the reconcile
        // exists for.
        DB::table('galleries')->where('id', $gallery->id)->update(['images_count' => 99]);

        $this->artisan('counters:recompute')->assertSuccessful();

        $this->assertSame(1, (int) DB::table('galleries')->where('id', $gallery->id)->value('images_count'));
    }

    // ── Caches and authorisation ─────────────────────────────────────────

    public function test_publishing_a_gallery_flushes_the_homepage(): void
    {
        $gallery = Gallery::factory()->draft()->create();

        app(HomepageService::class)->build();
        $this->assertTrue(Cache::has(HomepageService::CACHE_KEY));

        $this->actingAs($this->editor())
            ->put('/admin/galleries/'.$gallery->id, ['title' => $gallery->title, 'status' => 'published']);

        $this->assertFalse(Cache::has(HomepageService::CACHE_KEY));
    }

    public function test_a_reporter_cannot_touch_galleries(): void
    {
        $gallery = Gallery::factory()->create();
        $reporter = User::factory()->reporter()->create()->fresh();

        $this->actingAs($reporter)->get('/admin/galleries')->assertForbidden();
        $this->actingAs($reporter)->post('/admin/galleries', ['title' => 'x', 'status' => 'draft'])->assertForbidden();
        $this->actingAs($reporter)->get('/admin/galleries/'.$gallery->id.'/edit')->assertForbidden();
        $this->actingAs($reporter)->delete('/admin/galleries/'.$gallery->id)->assertForbidden();

        $this->assertSame(1, Gallery::count());
    }

    public function test_a_reader_gets_a_404(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->get('/admin/galleries')
            ->assertNotFound();
    }

    // ── The public hub, which is the point of all this ───────────────────

    public function test_a_published_gallery_reaches_the_public_hub(): void
    {
        $gallery = Gallery::factory()->create(['title' => 'বর্ষার ঢাকা']);
        $this->imageOn($gallery, 0);

        $this->get('/photo')->assertOk()->assertSee('বর্ষার ঢাকা');
        $this->get('/photo/'.$gallery->slug)->assertOk()->assertSee('বর্ষার ঢাকা');
    }

    /**
     * A credit with no caption used to render as nothing: the public template
     * nested the credit inside `@if ($image->caption)`. The upload form takes
     * one credit for a whole batch and captions one at a time, so "credited,
     * uncaptioned" is the normal state of a gallery an editor has just filled.
     */
    public function test_a_credit_shows_without_a_caption(): void
    {
        $gallery = Gallery::factory()->create();
        $image = $this->imageOn($gallery, 0);
        $image->update(['caption' => null, 'credit' => 'ছবি: প্রথম আলো']);

        $this->get('/photo/'.$gallery->slug)
            ->assertOk()
            ->assertSee('ছবি: প্রথম আলো');
    }

    public function test_a_draft_gallery_does_not(): void
    {
        $gallery = Gallery::factory()->draft()->create(['title' => 'অপ্রকাশিত']);

        $this->get('/photo')->assertOk()->assertDontSee('অপ্রকাশিত');
        $this->get('/photo/'.$gallery->slug)->assertNotFound();
    }
}
