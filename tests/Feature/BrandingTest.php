<?php

namespace Tests\Feature;

use App\Models\Page;
use Database\Seeders\SiteSeeder;
use Database\Seeders\Support\BanglaContent;
use Database\Seeders\Support\StaticPageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a fresh install presents itself as.
 *
 * None of this is behaviour a user triggers, which is exactly why it rotted:
 * a placeholder that renders is indistinguishable from a finished one until
 * somebody reads it. Every assertion here is a thing that shipped wrong once.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The six pages a reader opens *because* they want an answer. They were
     * seeded with the same generated filler as the 374 demo articles, so
     * "আমাদের সম্পর্কে" was sentence-shaped noise.
     *
     * Asserted against the generator rather than by eye: `BanglaContent` draws
     * from a fixed vocabulary, so if a body ever goes back to filler these
     * words reappear.
     */
    #[DataProvider('staticPages')]
    public function test_a_static_page_is_written_rather_than_generated(string $slug): void
    {
        $this->seed(SiteSeeder::class);

        $page = Page::where('slug', $slug)->firstOrFail();
        $text = strip_tags($page->body);

        $this->assertGreaterThan(400, mb_strlen($text), "{$slug} is too short to be real copy.");

        // Three or more of the filler vocabulary's stock verbs in one page is
        // not prose, it is BanglaContent.
        $tells = collect(['জানিয়েছেন', 'বিবৃতিতে', 'সংশ্লিষ্ট', 'কর্তৃপক্ষ', 'অন্যদিকে'])
            ->filter(fn (string $w) => str_contains($text, $w));

        $this->assertLessThan(3, $tells->count(), "{$slug} still reads as generated filler.");
    }

    public static function staticPages(): array
    {
        return array_map(fn ($s) => [$s], array_keys(StaticPageContent::all()));
    }

    public function test_every_static_page_renders_its_written_body(): void
    {
        $this->seed(SiteSeeder::class);

        foreach (array_keys(StaticPageContent::all()) as $slug) {
            $page = Page::where('slug', $slug)->firstOrFail();

            $this->get("/page/{$slug}")
                ->assertOk()
                ->assertSee($page->title);
        }
    }

    /**
     * The generator is still what the articles use, so this proves the tell
     * above actually tells. Without it a change to `BanglaContent`'s
     * vocabulary would quietly turn the assertion into one that cannot fail.
     */
    public function test_the_filler_detector_recognises_filler(): void
    {
        $filler = strip_tags(BanglaContent::paragraph(12));

        $tells = collect(['জানিয়েছেন', 'বিবৃতিতে', 'সংশ্লিষ্ট', 'কর্তৃপক্ষ', 'অন্যদিকে'])
            ->filter(fn (string $w) => str_contains($filler, $w));

        $this->assertGreaterThanOrEqual(3, $tells->count(), 'The filler tells no longer identify filler.');
    }

    /**
     * `demo:purge` deliberately keeps the imprint group, so whatever is seeded
     * here is what a launch ships unless somebody edits it. It held a
     * plausible Bangla name and a real Dhaka media-district address on fields
     * that are a legal requirement for a newspaper — which is worse than a
     * blank, because a blank gets noticed.
     */
    public function test_the_seeded_imprint_cannot_be_mistaken_for_a_real_one(): void
    {
        $this->seed(SiteSeeder::class);

        $this->assertDatabaseMissing('settings', ['key' => 'editor_name', 'value' => 'মোঃ আব্দুল করিম']);
        $this->assertDatabaseMissing('settings', ['key' => 'publisher_name', 'value' => 'সংবাদ মিডিয়া লিমিটেড']);

        foreach (['editor_name', 'publisher_name', 'office_address'] as $key) {
            $value = (string) \App\Models\Setting::where('key', $key)->value('value');

            $this->assertStringContainsString(
                'ডেমো', $value,
                "settings.{$key} does not declare itself a demo value."
            );
        }
    }

    /**
     * Bare `https://facebook.com` defaults rendered a full row of live social
     * buttons that took a reader to the front page of Facebook. The footer
     * already skipped empty values; nothing was empty.
     */
    public function test_no_social_link_points_at_a_bare_network_homepage(): void
    {
        $social = config('site.social');

        // Asserted on the whole set rather than inside the loop. With every
        // value blank — which is the default now — a per-item loop makes no
        // assertion at all and PHPUnit marks the test risky: it passes because
        // it checked nothing, which is the failure mode this whole file exists
        // to catch.
        $this->assertIsArray($social);
        $this->assertNotEmpty($social, 'The social block has gone from config/site.php.');

        $bare = collect($social)
            ->filter()
            ->reject(fn ($url) => (bool) preg_match('#^https?://[^/]+/.+#', (string) $url))
            ->keys();

        $this->assertSame(
            [], $bare->all(),
            'These point at a network front page rather than a profile: '.$bare->implode(', ')
        );
    }

    /**
     * `public/favicon.ico` was a zero-byte file. Browsers still request
     * `/favicon.ico` regardless of `<link rel="icon">`, and an empty 200 is
     * not the same as a 404 — it is a valid response that renders nothing and
     * gets cached.
     */
    public function test_the_favicon_is_a_real_icon(): void
    {
        $path = public_path('favicon.ico');

        $this->assertFileExists($path);
        $this->assertGreaterThan(100, filesize($path), 'favicon.ico is empty or a stub.');

        // ICONDIR: reserved 0, type 1, at least one image.
        [$reserved, $type, $count] = array_values(unpack('vreserved/vtype/vcount', file_get_contents($path, false, null, 0, 6)));

        $this->assertSame(0, $reserved);
        $this->assertSame(1, $type, 'Not an icon file.');
        $this->assertGreaterThan(0, $count);
    }

    /**
     * A maskable icon is cropped to whatever shape the launcher likes, so only
     * a *circle* of 80% diameter survives. The old icon-512 was declared
     * maskable while its content ran to 81% of the canvas, and its corners
     * were clipped on every circular launcher with nothing to say so.
     */
    public function test_the_manifest_maskable_icon_exists_and_is_its_own_file(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertIsArray($manifest);

        $maskable = collect($manifest['icons'])->firstWhere('purpose', 'maskable');
        $any = collect($manifest['icons'])->where('purpose', 'any')->pluck('src');

        $this->assertNotNull($maskable, 'The manifest declares no maskable icon.');
        $this->assertFileExists(public_path($maskable['src']));

        $this->assertNotContains(
            $maskable['src'], $any->all(),
            'One file cannot be both `any` and `maskable`: they need different safe areas.'
        );
    }

    public function test_every_icon_the_manifest_names_exists(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $named = collect($manifest['icons'])->pluck('src')
            ->merge(collect($manifest['shortcuts'] ?? [])->flatMap(fn ($s) => collect($s['icons'] ?? [])->pluck('src')))
            ->unique();

        $this->assertNotEmpty($named);

        foreach ($named as $src) {
            $this->assertFileExists(public_path($src));
        }
    }

    public function test_the_manifest_carries_the_configured_masthead(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame(config('site.name_bn'), $manifest['name']);
    }
}
