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
