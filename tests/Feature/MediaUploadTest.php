<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Media;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Where an upload lands, and what it is called.
 *
 * Files used to be `uploads/2026/08/<24 random chars>.jpg`, which told an
 * editor nothing: the media library showed a wall of thumbnails with no way to
 * tell which story any of them came from. They are now filed under the
 * headline they were uploaded for.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor])->fresh();
    }

    public function test_an_upload_is_filed_under_the_headline_it_belongs_to(): void
    {
        Storage::fake('public');

        $this->actingAs($this->editor())
            ->postJson(route('admin.media.store'), [
                'file' => UploadedFile::fake()->image('98.png', 800, 600),
                'for' => 'রপ্তানি আয় বাড়াতে প্রধান উপদেষ্টা',
            ])
            ->assertCreated();

        $media = Media::query()->firstOrFail();

        $this->assertSame(
            'uploads/'.now()->format('Y/m').'/রপ্তানি-আয়-বাড়াতে-প্রধান-উপদেষ্টা/98.png',
            $media->path,
        );

        // \p{M} again: without it বাড়াতে would lose its vowel signs and two
        // different headlines could land in the same folder.
        $this->assertStringContainsString('বাড়াতে', $media->path);
    }

    public function test_an_upload_with_no_context_lands_in_misc(): void
    {
        Storage::fake('public');

        $this->actingAs($this->editor())
            ->postJson(route('admin.media.store'), [
                'file' => UploadedFile::fake()->image('98.png', 800, 600),
            ])
            ->assertCreated();

        $this->assertSame(
            'uploads/'.now()->format('Y/m').'/misc/98.png',
            Media::query()->firstOrFail()->path,
        );
    }

    public function test_the_same_filename_twice_does_not_overwrite(): void
    {
        Storage::fake('public');
        $editor = $this->editor();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($editor)
                ->postJson(route('admin.media.store'), [
                    'file' => UploadedFile::fake()->image('98.png', 800, 600),
                    'for' => 'একই খবর',
                ])
                ->assertCreated();
        }

        $paths = Media::query()->orderBy('id')->pluck('path')->all();

        $this->assertStringEndsWith('/98.png', $paths[0]);
        $this->assertStringEndsWith('/98-2.png', $paths[1]);
        Storage::disk('public')->assertExists($paths[0]);
        Storage::disk('public')->assertExists($paths[1]);
    }

    public function test_derivatives_sit_beside_the_original(): void
    {
        Storage::fake('public');

        $media = app(ImageService::class)->store(
            UploadedFile::fake()->image('98.png', 1400, 900),
            null,
            [],
            'কোনো এক খবর',
        );

        foreach ($media->conversions as $path) {
            $this->assertStringStartsWith(dirname($media->path).'/', $path);
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_an_avatar_becomes_a_tracked_upload_and_the_old_one_is_released(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => UserRole::Reader])->fresh();

        $this->actingAs($user)
            ->patch(route('account.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('me.jpg', 400, 400),
            ])
            ->assertRedirect();

        $first = $user->fresh()->avatar;

        $this->assertStringContainsString('/avatars/', $first);
        $this->assertNotNull(Media::query()->where('path', $first)->first(), 'avatar should be in the media library');

        $this->actingAs($user)
            ->patch(route('account.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => UploadedFile::fake()->image('me2.jpg', 400, 400),
            ])
            ->assertRedirect();

        // The previous avatar had no other referent, so it goes entirely.
        $this->assertNotSame($first, $user->fresh()->avatar);
        $this->assertDatabaseMissing('media', ['path' => $first]);
        Storage::disk('public')->assertMissing($first);
    }
}
