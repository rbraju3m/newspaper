<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Page;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `demo:purge` — what it takes out, and what it must not.
 *
 * The command exists to be run once, by hand, against a database nobody wants
 * to restore. Its two failure modes are opposite and both expensive: leaving a
 * login whose password is the word "password", or deleting the taxonomy and
 * layout a newsroom would then have to rebuild by hand.
 */
class DemoPurgeTest extends TestCase
{
    use RefreshDatabase;

    /** The shape of a seeded install: content, staff, structure. */
    private function seedDemo(): Category
    {
        $category = Category::factory()->create();

        $admin = User::factory()->create([
            'email' => 'owner@newsroom.example',
            'role' => UserRole::Admin,
        ]);

        // The three UserSeeder logins, one of them an admin.
        User::factory()->create(['email' => 'admin@newspaper.test', 'role' => UserRole::Admin]);
        User::factory()->create(['email' => 'editor@newspaper.test', 'role' => UserRole::Editor]);
        User::factory()->create(['email' => 'reader@newspaper.test', 'role' => UserRole::Reader]);

        $reporter = User::factory()->create(['role' => UserRole::Reporter]);

        $article = Article::factory()->create([
            'category_id' => $category->id,
            'author_id' => $reporter->id,
        ]);

        Comment::factory()->create(['article_id' => $article->id, 'user_id' => $admin->id]);
        $article->tags()->attach(Tag::factory()->create()->id);
        $article->topics()->attach(Topic::factory()->create()->id);

        DB::table('ads')->insert(['title' => 'ডেমো বিজ্ঞাপন', 'position' => 'header']);
        DB::table('polls')->insert(['question' => 'ডেমো প্রশ্ন', 'slug' => 'demo']);
        DB::table('home_blocks')->insert(['type' => 'hero', 'position' => 1]);
        DB::table('settings')->insert(['key' => 'site_name', 'value' => 'দৈনিক সংবাদ']);
        Page::factory()->create(['slug' => 'about']);

        // Something denormalised that will be left pointing at nothing.
        DB::table('categories')->where('id', $category->id)->update(['articles_count' => 1]);

        return $category;
    }

    public function test_it_deletes_the_demo_content(): void
    {
        $this->seedDemo();

        $this->artisan('demo:purge --force')->assertSuccessful();

        foreach (['articles', 'comments', 'tags', 'topics', 'ads', 'polls', 'article_tag', 'article_topic'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} should be empty");
        }
    }

    public function test_it_keeps_the_shell_a_newsroom_would_have_to_rebuild(): void
    {
        $this->seedDemo();

        $this->artisan('demo:purge --force')->assertSuccessful();

        $this->assertSame(1, DB::table('categories')->count());
        $this->assertSame(1, DB::table('home_blocks')->count());
        $this->assertSame(1, DB::table('settings')->count());
        $this->assertSame(1, DB::table('pages')->count());
    }

    public function test_it_keeps_a_real_admin_and_deletes_everyone_else(): void
    {
        $this->seedDemo();

        $this->artisan('demo:purge --force')->assertSuccessful();

        $this->assertSame(['owner@newsroom.example'], User::query()->pluck('email')->all());
    }

    /**
     * `admin@newspaper.test` is an admin, and it is exactly the login being
     * purged: a known address whose password is the word "password".
     */
    public function test_the_seeded_logins_go_even_when_one_is_an_admin(): void
    {
        $this->seedDemo();

        $this->artisan('demo:purge --force')->assertSuccessful();

        $this->assertFalse(User::query()->where('email', 'admin@newspaper.test')->exists());
    }

    public function test_keep_preserves_an_account_that_is_not_an_admin(): void
    {
        $this->seedDemo();
        User::factory()->create(['email' => 'newsdesk@newsroom.example', 'role' => UserRole::Editor]);

        $this->artisan('demo:purge --force --keep=newsdesk@newsroom.example')->assertSuccessful();

        $this->assertTrue(User::query()->where('email', 'newsdesk@newsroom.example')->exists());
    }

    /** Deleting every account leaves a site its owner cannot sign in to. */
    public function test_it_refuses_rather_than_locking_everybody_out(): void
    {
        Category::factory()->create();
        User::factory()->create(['role' => UserRole::Reporter]);

        $this->artisan('demo:purge --force')->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->seedDemo();

        $this->artisan('demo:purge --dry-run')->assertSuccessful();

        $this->assertSame(1, DB::table('articles')->count());
        $this->assertSame(5, User::query()->count());
    }

    /** A media row owns an original and a ladder; both come off the disk. */
    public function test_it_takes_the_demo_imagery_off_the_disk(): void
    {
        Storage::fake('public');
        $this->seedDemo();

        Storage::disk('public')->put('uploads/2026/08/demo/lead.jpg', 'original');
        Storage::disk('public')->put('uploads/2026/08/demo/lead-w320.webp', 'derivative');

        Media::factory()->create([
            'disk' => 'public',
            'path' => 'uploads/2026/08/demo/lead.jpg',
            'conversions' => ['w320' => 'uploads/2026/08/demo/lead-w320.webp'],
        ]);

        $this->artisan('demo:purge --force')->assertSuccessful();

        Storage::disk('public')->assertMissing('uploads/2026/08/demo/lead.jpg');
        Storage::disk('public')->assertMissing('uploads/2026/08/demo/lead-w320.webp');
        $this->assertSame(0, Media::query()->count());
    }

    /** An article's `image` is a bare path, not a media row, and still ours. */
    public function test_it_removes_files_referenced_by_bare_path(): void
    {
        Storage::fake('public');
        $category = $this->seedDemo();

        Storage::disk('public')->put('uploads/2026/08/demo/story.jpg', 'x');

        Article::factory()->create([
            'category_id' => $category->id,
            'image' => 'uploads/2026/08/demo/story.jpg',
        ]);

        $this->artisan('demo:purge --force')->assertSuccessful();

        Storage::disk('public')->assertMissing('uploads/2026/08/demo/story.jpg');
    }

    /** An external avatar belongs to Google, not to us. */
    public function test_it_leaves_remote_avatars_alone(): void
    {
        Storage::fake('public');
        $this->seedDemo();

        User::factory()->create([
            'role' => UserRole::Reader,
            'avatar' => 'https://lh3.googleusercontent.com/a/demo',
        ]);

        $this->artisan('demo:purge --force')->assertSuccessful();

        // Nothing to assert on disk; the point is that it does not throw
        // trying to delete a URL, and the purge still completes.
        $this->assertSame(1, User::query()->count());
    }

    public function test_it_resets_counters_that_now_point_at_nothing(): void
    {
        $category = $this->seedDemo();

        $this->artisan('demo:purge --force')->assertSuccessful();

        $this->assertSame(0, (int) DB::table('categories')->where('id', $category->id)->value('articles_count'));
    }

    /**
     * A cached homepage holds serialised models whose rows are gone.
     * `config/cache.php` deserialises them on the next request.
     */
    public function test_it_flushes_caches_that_hold_deleted_models(): void
    {
        $this->seedDemo();

        Cache::forever('layout.categories', ['stale']);
        (new HomepageService)->build();

        $this->artisan('demo:purge --force')->assertSuccessful();

        $this->assertNull(Cache::get('layout.categories'));
    }
}
