<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Comment;
use App\Models\User;
use App\Support\Avatar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fallback avatar, which used to be somebody else's server.
 *
 * `User::avatar_url` fell back to `ui-avatars.com` for anyone who had not
 * uploaded a photograph — 36 of the 37 users on the development box — so
 * every author page and every comment thread sent a reader's IP address and
 * the page they were on to a third party, for something purely decorative.
 *
 * What matters most here is the *absence*: that no rendered page reaches for
 * an external host. That is asserted against real pages rather than against
 * the accessor, because the accessor is not where a regression would show up.
 */
class AvatarTest extends TestCase
{
    use RefreshDatabase;

    // ── Nothing leaves the site ──────────────────────────────────────────

    public function test_the_fallback_is_not_a_third_party_url(): void
    {
        $user = User::factory()->create(['avatar' => null])->fresh();

        $this->assertStringStartsWith('data:image/svg+xml', $user->avatar_url);
        $this->assertStringNotContainsString('ui-avatars', $user->avatar_url);
    }

    /**
     * The pages that carry faces, checked for the host itself rather than for
     * the accessor's return value — a template reaching for `ui-avatars` some
     * other way would still fail this.
     *
     * **Each page is checked for an avatar before being checked for a
     * third-party one.** An `assertDontSee` on a page that happens to render
     * no faces passes whatever the accessor does, which is the shape of
     * assertion this repository has been caught by before.
     */
    public function test_no_page_carrying_a_face_requests_a_third_party_avatar(): void
    {
        $author = User::factory()->reporter()->create(['avatar' => null])->fresh();

        $article = Article::factory()->for($author, 'author')->create();
        Comment::factory()->for($article)->create();

        $pages = [
            'the author page' => route('author.show', $author),
            'an article with a byline and an approved comment' => $article->url,
        ];

        foreach ($pages as $what => $url) {
            $html = $this->get($url)->assertOk()->getContent();

            // The control: this page really does render a face.
            $this->assertStringContainsString(
                'data:image/svg+xml', $html, "No avatar rendered on {$what}."
            );

            $this->assertStringNotContainsString('ui-avatars', $html, "A third-party avatar on {$what}.");
        }
    }

    // ── What it draws ────────────────────────────────────────────────────

    /**
     * Bangla is the reason this is an SVG rather than a drawn PNG. GD does no
     * complex shaping — which is why `brand:icons` is wordless — but a
     * browser shapes SVG text, so the initials of a Bangla name survive.
     */
    public function test_bangla_initials_reach_the_svg_intact(): void
    {
        $user = User::factory()->create(['name' => 'বার্তা সম্পাদক', 'avatar' => null])->fresh();

        $this->assertSame('বস', $user->initials);
        $this->assertStringContainsString('বস', rawurldecode($user->avatar_url));
    }

    /**
     * Printed inside `src="…"`, and in one place inside a single-quoted Alpine
     * expression. `rawurlencode` leaves only `A-Za-z0-9-_.~` and `%`, so the
     * URI cannot carry a quote, a space or an angle bracket into any of them.
     */
    public function test_the_data_uri_is_inert_in_an_attribute(): void
    {
        $uri = Avatar::dataUri('<script>&"\'');

        $this->assertSame(
            '', preg_replace('/^data:image\/svg\+xml;charset=UTF-8,[A-Za-z0-9\-_.~%]+$/', '', $uri),
            'The data URI carried a character that is not URI-safe.',
        );
    }

    /** A name with markup in it must still produce a valid document. */
    public function test_a_name_holding_markup_still_draws(): void
    {
        $svg = rawurldecode(substr(Avatar::dataUri('<&>'), strlen('data:image/svg+xml;charset=UTF-8,')));

        $this->assertNotFalse(simplexml_load_string($svg), 'The SVG did not parse.');
        $this->assertStringContainsString('&lt;&amp;&gt;', $svg);
    }

    // ── The palette ──────────────────────────────────────────────────────

    /**
     * Computed here rather than trusted from the comment beside the palette.
     * White initials on a colour that does not clear 4.5:1 is unreadable, and
     * the site's own category palette contains one such colour —
     * `--color-cat-lifestyle`, `#DB6B00`, at 3.43:1 — so this is the check
     * that keeps it out.
     */
    public function test_every_palette_colour_carries_white_text_at_wcag_aa(): void
    {
        foreach (Avatar::palette() as $hex) {
            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio = $this->contrastWithWhite($hex),
                "{$hex} is {$ratio}:1 against white, below WCAG AA.",
            );
        }
    }

    /** The one the palette leaves out, pinned so it cannot drift back in. */
    public function test_the_below_aa_category_colour_is_not_in_the_palette(): void
    {
        $this->assertLessThan(4.5, $this->contrastWithWhite('#DB6B00'));
        $this->assertNotContains('#DB6B00', Avatar::palette());
    }

    /**
     * Keyed on the name rather than the id, so the same person looks the same
     * across a re-seed and between environments.
     */
    public function test_a_readers_colour_is_stable(): void
    {
        $this->assertSame(
            Avatar::colorFor('বার্তা সম্পাদক'),
            Avatar::colorFor('বার্তা সম্পাদক'),
        );

        $first = User::factory()->create(['name' => 'রুবিনা হক', 'avatar' => null])->fresh();
        $again = User::factory()->create(['name' => 'রুবিনা হক', 'avatar' => null])->fresh();

        $this->assertSame($first->avatar_url, $again->avatar_url);
    }

    /**
     * The point of the change: a thread of readers is not a column of
     * identical circles. Twenty distinct names must not collapse onto one
     * colour — a hash that always returned index 0 would pass every other
     * test in this file.
     */
    public function test_different_readers_get_different_colours(): void
    {
        $colours = [];

        for ($i = 0; $i < 20; $i++) {
            $colours[] = Avatar::colorFor("reader-{$i}");
        }

        $this->assertGreaterThan(
            4, count(array_unique($colours)),
            'The palette collapsed onto too few colours to tell readers apart.',
        );
    }

    // ── An uploaded photograph still wins ────────────────────────────────

    public function test_an_uploaded_avatar_is_used_instead(): void
    {
        $user = User::factory()->create(['avatar' => 'uploads/avatars/a.webp'])->fresh();

        $this->assertSame(asset('storage/uploads/avatars/a.webp'), $user->avatar_url);
        $this->assertSame($user->avatar_url, $user->avatar_photo_url);
    }

    public function test_an_external_avatar_url_is_passed_through(): void
    {
        // OAuth stores the provider's URL directly.
        $user = User::factory()->create(['avatar' => 'https://lh3.example/photo.jpg'])->fresh();

        $this->assertSame('https://lh3.example/photo.jpg', $user->avatar_url);
    }

    // ── Structured data wants a real photograph or nothing ───────────────

    public function test_avatar_photo_url_is_null_when_nothing_was_uploaded(): void
    {
        $user = User::factory()->create(['avatar' => null])->fresh();

        $this->assertNull($user->avatar_photo_url);
    }

    /**
     * The author page published its generated fallback as `Person.image`, so
     * a third-party URL stood as the canonical picture of a staff member who
     * had never uploaded one. No photograph is the honest answer.
     */
    public function test_the_author_json_ld_omits_the_image_when_there_is_no_photograph(): void
    {
        $author = User::factory()->reporter()->create(['avatar' => null])->fresh();

        $schema = $this->schemaFor(route('author.show', $author));

        $this->assertArrayNotHasKey('image', $schema);
        $this->assertSame($author->name, $schema['name']);
    }

    public function test_the_author_json_ld_carries_a_real_photograph(): void
    {
        $author = User::factory()->reporter()
            ->create(['avatar' => 'uploads/avatars/a.webp'])->fresh();

        $this->assertSame(
            asset('storage/uploads/avatars/a.webp'),
            $this->schemaFor(route('author.show', $author))['image'],
        );
    }

    /** WCAG relative-luminance contrast of white against one hex colour. */
    private function contrastWithWhite(string $hex): float
    {
        $channel = function (int $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        };

        [$r, $g, $b] = array_map(
            fn (string $pair): float => $channel(hexdec($pair)),
            str_split(ltrim($hex, '#'), 2),
        );

        return 1.05 / (0.2126 * $r + 0.7152 * $g + 0.0722 * $b + 0.05);
    }

    /** The JSON-LD block on a page, decoded. */
    private function schemaFor(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        $this->assertTrue(
            (bool) preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m),
            'No JSON-LD on the page.',
        );

        return json_decode(trim($m[1]), true, flags: JSON_THROW_ON_ERROR);
    }
}
