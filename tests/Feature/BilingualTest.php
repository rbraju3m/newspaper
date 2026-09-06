<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The English edition — a translated edition, not a second interface language.
 *
 * Bangla is unprefixed and English lives under `/en`. An article in either
 * edition is its **own row**, with its own slug, byline, publication time and
 * comment thread, linked to its counterpart by `articles.translation_of`.
 *
 * The failure this file exists to catch is not a 404. It is **leakage** — one
 * edition's stories appearing in the other's listings, feeds or sitemap —
 * because that has no error, no log line and no visual break on a page whose
 * chrome is already correct. `articles.locale` was on the table from the first
 * migration and nothing read it, so before this every English story published
 * would have gone straight onto the Bangla front page.
 */
class BilingualTest extends TestCase
{
    use RefreshDatabase;

    private Category $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->section = Category::factory()->create([
            'name' => 'খেলা', 'name_en' => 'Sport', 'slug' => 'sports',
        ]);
    }

    /**
     * `excerpt` and `body` are pinned, not left to the factory.
     *
     * `BanglaContent` writes Bangla into both, and the chrome sweep below
     * looks for Bangla script anywhere in an English page's text — a factory
     * excerpt would make it fail on the story rather than on the interface,
     * which is the assertion being made about a different thing entirely.
     */
    private function article(string $locale, array $attributes = []): Article
    {
        $english = [
            'title' => 'An English headline',
            'excerpt' => 'An English standfirst for the story.',
            'body' => '<p>An English paragraph.</p>',
            'kicker' => null,
            'dateline' => 'Dhaka',
        ];

        $bangla = [
            'title' => 'বাংলা শিরোনাম',
        ];

        // `category` is loaded here rather than at each call site: every test
        // below reads `->url`, which reads `category->path`, and a factory
        // model holds only what the factory set. Strict mode makes that a
        // violation rather than an extra query.
        return Article::factory()->for($this->section)->create(
            $attributes + ($locale === 'bn' ? $bangla : $english) + ['locale' => $locale],
        )->load('category');
    }

    /** A published pair, Bangla original and English counterpart. */
    private function pair(): array
    {
        $bn = $this->article('bn');
        $en = $this->article('en', ['translation_of' => $bn->id]);

        return [$bn->fresh()->load('category'), $en->fresh()->load('category')];
    }

    // ── Routing ──────────────────────────────────────────────────────────

    /**
     * Matched against the route collection, not read off `route:list`.
     *
     * `route:list` sorts alphabetically and says nothing about match order,
     * and the ordering here is the whole risk: `/{category}` is constrained
     * `.*`, so an `/en` group registered after it would never be reached and
     * `/en` would resolve as a **section named "en"** — a 404 that looks like
     * a missing category rather than a misplaced route block.
     */
    public function test_every_english_url_reaches_its_own_route(): void
    {
        $expected = [
            '/' => 'home',
            '/en' => 'en.home',
            '/search' => 'search',
            '/en/search' => 'en.search',
            '/rss' => 'feed.rss',
            '/en/rss' => 'en.feed.rss',
            '/en/sitemap.xml' => 'en.feed.sitemap',
            '/en/api/breaking' => 'en.api.breaking',
            '/author/x' => 'author.show',
            '/en/author/x' => 'en.author.show',
            '/topic/x' => 'topic.show',
            '/en/topic/x' => 'en.topic.show',
            '/tag/x' => 'tag.show',
            '/en/tag/x' => 'en.tag.show',
            '/sports/cricket' => 'category.show',
            '/en/sports/cricket' => 'en.category.show',
            '/sports/cricket/9/x' => 'article.show',
            '/en/sports/cricket/9/x' => 'en.article.show',
            // The rule the whole file order exists for.
            '/login' => 'login',
        ];

        $routes = Route::getRoutes();

        foreach ($expected as $path => $name) {
            $this->assertSame(
                $name,
                $routes->match(Request::create($path, 'GET'))->getName(),
                "{$path} did not reach {$name}.",
            );
        }
    }

    public function test_the_english_edition_answers(): void
    {
        $this->article('en');

        $this->get('/en')->assertOk();
        $this->get('/en/sports')->assertOk();
        $this->get('/en/search?q=headline')->assertOk();
        $this->get('/en/rss')->assertOk();
        $this->get('/en/sitemap.xml')->assertOk();
    }

    // ── Leakage, which is the whole point ────────────────────────────────

    public function test_an_english_story_stays_out_of_every_bangla_surface(): void
    {
        $en = $this->article('en', ['title' => 'Leaked into Bangla']);
        $this->article('bn');

        foreach (['/', '/latest', '/sports', '/rss', '/sitemap.xml'] as $url) {
            $this->get($url)->assertOk()->assertDontSee($en->title, false);
        }
    }

    public function test_a_bangla_story_stays_out_of_every_english_surface(): void
    {
        $bn = $this->article('bn', ['title' => 'বাংলা ফাঁস']);
        $this->article('en');

        foreach (['/en', '/en/sports', '/en/rss', '/en/sitemap.xml'] as $url) {
            $this->get($url)->assertOk()->assertDontSee($bn->title, false);
        }
    }

    /**
     * The control for the two tests above: without it they pass on a site
     * that renders no articles at all, which is exactly what a broken locale
     * scope would produce.
     */
    public function test_each_edition_does_show_its_own_stories(): void
    {
        [$bn, $en] = $this->pair();

        $this->get('/latest')->assertOk()->assertSee($bn->title, false);
        $this->get('/en')->assertOk()->assertSee($en->title, false);
    }

    /**
     * `ArticleQuery::related()`'s curated list is the one query that does not
     * go through `deferred()`, so it is the one that could leak. It did: an
     * English article showed its original's Bangla related stories.
     */
    public function test_curated_related_stories_do_not_cross_editions(): void
    {
        [$bn, $en] = $this->pair();

        $banglaRelated = $this->article('bn', ['title' => 'সম্পর্কিত বাংলা']);
        $en->relatedArticles()->attach($banglaRelated->id);

        $this->get($en->url)->assertOk()->assertDontSee($banglaRelated->title, false);
    }

    // ── A URL belongs to its article, not to the request ─────────────────

    public function test_an_articles_url_follows_its_own_edition(): void
    {
        [$bn, $en] = $this->pair();

        $this->assertStringNotContainsString('/en/', $bn->url);
        $this->assertStringContainsString('/en/', $en->url);
    }

    /**
     * The id lookup is locale-blind, so a Bangla story *can* be requested at
     * an `/en` URL. It must not render there: `$article->url` is the Bangla
     * one, and the canonical redirect carries the reader back out of the
     * edition rather than serving a Bangla body inside an English frame.
     */
    public function test_a_bangla_story_requested_under_en_redirects_to_its_own_edition(): void
    {
        [$bn] = $this->pair();

        $this->get('/en/sports/'.$bn->id.'/'.$bn->slug)
            ->assertRedirect($bn->url);
    }

    public function test_an_english_story_canonicalises_within_its_own_edition(): void
    {
        [, $en] = $this->pair();

        $this->get('/en/sports/'.$en->id.'/a-stale-slug')
            ->assertRedirect($en->url);
    }

    // ── The switcher and hreflang ────────────────────────────────────────

    public function test_a_translated_story_offers_the_other_edition_both_ways(): void
    {
        [$bn, $en] = $this->pair();

        $this->get($bn->url)->assertOk()->assertSee($en->url, false);
        $this->get($en->url)->assertOk()->assertSee($bn->url, false);
    }

    public function test_a_translated_story_pairs_itself_with_hreflang(): void
    {
        [$bn, $en] = $this->pair();

        $html = $this->get($en->url)->assertOk()->getContent();

        foreach (['en-BD', 'bn-BD', 'x-default'] as $tag) {
            $this->assertStringContainsString('hreflang="'.$tag.'"', $html, "No {$tag} alternate.");
        }

        // x-default is the Bangla edition: it is the original, and it is where
        // a reader with no matching preference should land.
        $this->assertMatchesRegularExpression(
            '/hreflang="x-default"\s*\n?\s*href="'.preg_quote($bn->url, '/').'"/', $html,
        );
    }

    /**
     * An untranslated story must offer nothing. A switcher that 404s for the
     * 90% of stories nobody has translated teaches a reader within two clicks
     * that it does not work, and a broken `<link rel="alternate">` is read as
     * the pair being wrong rather than as one side not existing.
     */
    public function test_an_untranslated_story_offers_no_switcher_and_no_alternates(): void
    {
        $lonely = $this->article('bn');

        $html = $this->get($lonely->url)->assertOk()->getContent();

        $this->assertStringNotContainsString('hreflang="x-default"', $html);
        $this->assertStringNotContainsString('/en/sports/', $html);
    }

    /** A draft translation is not a page, so it is not an alternate either. */
    public function test_a_draft_translation_is_not_offered(): void
    {
        $bn = $this->article('bn');
        $this->article('en', [
            'translation_of' => $bn->id,
            'status' => \App\Enums\ArticleStatus::Draft,
            'published_at' => null,
        ]);

        $this->assertNull($bn->fresh()->counterpart());
        $this->get($bn->url)->assertOk()->assertDontSee('hreflang="x-default"', false);
    }

    /** The pointer can be on either end; a reader must not be able to tell. */
    public function test_the_counterpart_is_found_from_whichever_end_holds_the_pointer(): void
    {
        [$bn, $en] = $this->pair();

        $this->assertSame($en->id, $bn->counterpart()?->id);
        $this->assertSame($bn->id, $en->counterpart()?->id);
    }

    // ── The interface ────────────────────────────────────────────────────

    public function test_an_english_page_declares_itself_english(): void
    {
        $this->article('en');

        $this->get('/en')->assertOk()
            ->assertSee('<html lang="en"', false)
            ->assertSee('content="en_BD"', false);

        $this->get('/latest')->assertOk()->assertSee('<html lang="bn"', false);
    }

    /**
     * Asserted as an absence of Bangla script anywhere in the page's text,
     * not as the presence of a few English words. A page that translated the
     * eight strings somebody thought of and left forty untouched passes every
     * `assertSee('Search')` ever written.
     *
     * Article *content* is excluded by construction: the fixtures in this
     * test are English articles, so any Bangla left is chrome.
     */
    public function test_no_bangla_chrome_survives_on_an_english_page(): void
    {
        $this->article('en');

        foreach (['/en', '/en/sports', '/en/search?q=x'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match_all('/>[^<>]*[\x{0980}-\x{09FF}][^<>]*</u', $html, $found);

            $this->assertEmpty(
                array_filter(array_map('trim', array_map(fn ($s) => trim($s, '<>'), $found[0]))),
                "Bangla chrome on {$url}: ".implode(' | ', array_slice($found[0], 0, 5)),
            );
        }
    }

    /** The control: the same sweep must find plenty on the Bangla site. */
    public function test_the_bangla_chrome_check_can_fail(): void
    {
        $this->article('bn');

        $html = $this->get('/latest')->assertOk()->getContent();
        preg_match_all('/>[^<>]*[\x{0980}-\x{09FF}][^<>]*</u', $html, $found);

        $this->assertNotEmpty($found[0], 'The detector found no Bangla on the Bangla site.');
    }

    public function test_numbers_and_dates_follow_the_edition(): void
    {
        [$bn, $en] = $this->pair();

        $this->get($bn->url)->assertOk()->assertSee('মিনিট পড়া');
        $this->get($en->url)->assertOk()->assertSee('min read');
    }

    // ── Section names ────────────────────────────────────────────────────

    public function test_a_section_is_named_in_the_edition_being_read(): void
    {
        $this->article('en');

        $this->get('/en/sports')->assertOk()->assertSee('Sport')->assertDontSee('খেলা');
        $this->get('/sports')->assertOk()->assertSee('খেলা');
    }

    /**
     * A missing English name falls back to the Bangla one rather than to the
     * slug: a heading reading "law-court" is worse than one reading
     * "আইন ও আদালত" on an English page.
     */
    public function test_a_section_with_no_english_name_falls_back_to_bangla(): void
    {
        $unnamed = Category::factory()->create(['name' => 'আইন ও আদালত', 'name_en' => null]);

        $this->assertSame('আইন ও আদালত', $unnamed->display_name);

        app()->setLocale(Locale::ALTERNATE);
        $this->assertSame('আইন ও আদালত', $unnamed->display_name);
    }

    /**
     * `CategorySeeder::NAMES_EN` is keyed by slug and `TREE` is not, so the
     * two can drift apart silently — a section added to one and not the other
     * is a Bangla heading on an English page that nobody notices until they
     * open that section.
     */
    public function test_every_seeded_section_has_an_english_name(): void
    {
        $this->seed(\Database\Seeders\CategorySeeder::class);

        $this->assertSame(
            0, Category::whereNull('name_en')->count(),
            'A seeded section has no English name: '
                .Category::whereNull('name_en')->pluck('slug')->implode(', '),
        );
    }

    // ── Topics and tags ──────────────────────────────────────────────────

    /**
     * Both were **hidden** on `/en` when the edition shipped, because neither
     * table had an English name and neither had an `/en` route — a Bangla chip
     * on an English page is a word the reader cannot read linking out of the
     * edition. Both now exist, so the hiding is gone and the naming has to
     * work instead.
     */
    public function test_a_topic_and_a_tag_are_named_in_the_edition_being_read(): void
    {
        $topic = Topic::factory()->create([
            'name' => 'বিশ্বকাপ ২০২৬', 'name_en' => 'World Cup 2026', 'slug' => 'world-cup-2026',
        ]);
        $tag = Tag::create(['name' => 'ক্রিকেট', 'name_en' => 'Cricket']);

        $bn = $this->article('bn');
        $en = $this->article('en');
        $bn->topics()->attach($topic);
        $en->topics()->attach($topic);
        $bn->tags()->attach($tag);
        $en->tags()->attach($tag);

        $this->get(route('topic.show', $topic))->assertOk()
            ->assertSee('বিশ্বকাপ ২০২৬')->assertDontSee('World Cup 2026');

        $this->get(route('en.topic.show', $topic))->assertOk()
            ->assertSee('World Cup 2026')->assertDontSee('বিশ্বকাপ ২০২৬');

        $this->get(route('en.tag.show', $tag))->assertOk()
            ->assertSee('Cricket')->assertDontSee('ক্রিকেট', false);
    }

    /** A listing is a listing: it must not show the other edition's stories. */
    public function test_topic_and_tag_listings_are_scoped_to_their_edition(): void
    {
        $topic = Topic::factory()->create(['name_en' => 'A topic']);
        $tag = Tag::create(['name' => 'ট্যাগ', 'name_en' => 'A tag']);

        $bn = $this->article('bn', ['title' => 'বাংলা ফাঁস']);
        $en = $this->article('en', ['title' => 'The English one']);

        foreach ([$bn, $en] as $a) {
            $a->topics()->attach($topic);
            $a->tags()->attach($tag);
        }

        foreach ([route('en.topic.show', $topic), route('en.tag.show', $tag)] as $url) {
            $this->get($url)->assertOk()
                ->assertSee($en->title, false)
                ->assertDontSee($bn->title, false);
        }

        foreach ([route('topic.show', $topic), route('tag.show', $tag)] as $url) {
            $this->get($url)->assertOk()
                ->assertSee($bn->title, false)
                ->assertDontSee($en->title, false);
        }
    }

    /**
     * The same fallback sections have, for the same reason: a heading reading
     * "world-cup-2026" is worse than one reading the Bangla name.
     */
    public function test_a_topic_or_tag_with_no_english_name_falls_back_to_bangla(): void
    {
        $topic = Topic::factory()->create(['name' => 'অনামী বিষয়', 'name_en' => null]);
        $tag = Tag::create(['name' => 'অনামী ট্যাগ', 'name_en' => null]);

        app()->setLocale(Locale::ALTERNATE);

        $this->assertSame('অনামী বিষয়', $topic->display_name);
        $this->assertSame('অনামী ট্যাগ', $tag->display_name);
    }

    /**
     * The description is the one field whose fallback goes the **other** way.
     * A name has to render something or the page has no heading; a
     * description is optional everywhere, so nothing beats a Bangla paragraph
     * sitting under an English one.
     */
    public function test_a_topic_description_is_dropped_rather_than_shown_in_bangla(): void
    {
        $topic = Topic::factory()->create([
            'name' => 'বিষয়', 'name_en' => 'Topic',
            'description' => 'বাংলা বিবরণ', 'description_en' => null,
        ]);

        $this->get(route('topic.show', $topic))->assertOk()->assertSee('বাংলা বিবরণ');
        $this->get(route('en.topic.show', $topic))->assertOk()->assertDontSee('বাংলা বিবরণ');
    }

    public function test_a_topic_and_a_tag_pair_themselves_with_hreflang(): void
    {
        $topic = Topic::factory()->create(['name_en' => 'A topic']);
        $tag = Tag::create(['name' => 'ট্যাগ', 'name_en' => 'A tag']);

        foreach ([
            route('en.topic.show', $topic) => route('topic.show', $topic),
            route('en.tag.show', $tag) => route('tag.show', $tag),
        ] as $english => $bangla) {
            $html = $this->get($english)->assertOk()->getContent();

            $this->assertStringContainsString('hreflang="en-BD"', $html);
            $this->assertMatchesRegularExpression(
                '/hreflang="x-default"\s*\n?\s*href="'.preg_quote($bangla, '/').'"/', $html,
                "x-default on {$english} does not name the Bangla edition.",
            );
        }
    }

    /**
     * The tag strip under an English article was guarded on
     * `Locale::isDefault()` and is not any more. Its chips must stay inside
     * the edition: an English page whose only outbound taxonomy links go to
     * the Bangla site is the thing the guard existed to prevent.
     */
    public function test_an_english_articles_tags_stay_inside_the_english_edition(): void
    {
        $tag = Tag::create(['name' => 'নির্বাচন', 'name_en' => 'Election']);
        [, $en] = $this->pair();
        $en->tags()->attach($tag);

        $html = $this->get($en->url)->assertOk()->getContent();

        $this->assertStringContainsString(route('en.tag.show', $tag), $html);
        $this->assertStringContainsString('Election', $html);
        $this->assertStringNotContainsString(route('tag.show', $tag).'"', $html);
    }

    /**
     * The trending rail is cached once for both editions — the rows are the
     * same and only the printed column differs — so `name_en` has to be in
     * the select. A column missing from a **cached** model is a
     * `MissingAttributeException` that no lazy-loading guard can see, because
     * `unserialize()` fires no events.
     */
    public function test_the_trending_rail_renders_in_both_editions_from_one_cache_entry(): void
    {
        Topic::factory()->create([
            'name' => 'ট্রেন্ডিং বিষয়', 'name_en' => 'A trending topic',
            'is_active' => true, 'is_trending' => true,
        ]);

        $this->article('bn');
        $this->article('en');

        // Bangla first, so its payload is the one that would be reused.
        $this->get('/latest')->assertOk()->assertSee('ট্রেন্ডিং বিষয়');
        $this->get('/en')->assertOk()->assertSee('A trending topic');
    }

    /**
     * `ContentSeeder::TAGS` and `TOPICS` carry the English name in the same
     * tuple as the Bangla one, so the two cannot drift — but a name added to
     * one list and not the other would be a Bangla chip on an English page,
     * which is what all of this exists to remove.
     */
    public function test_every_seeded_topic_and_tag_has_an_english_name(): void
    {
        $this->seed(\Database\Seeders\CategorySeeder::class);
        $this->seed(\Database\Seeders\ContentSeeder::class);

        $this->assertSame(0, Topic::whereNull('name_en')->count(),
            'A seeded topic has no English name: '.Topic::whereNull('name_en')->pluck('slug')->implode(', '));

        $this->assertSame(0, Tag::whereNull('name_en')->count(),
            'A seeded tag has no English name: '.Tag::whereNull('name_en')->pluck('name')->implode(', '));
    }

    // ── The byline block ─────────────────────────────────────────────────

    /**
     * The author card was the last surface `/en` hid, and it was hidden on
     * `Locale::isDefault()`. It is guarded on the **edition's own biography**
     * now, which gives the same answer for a reporter who has only a Bangla
     * one and a better answer for a reporter who has both.
     */
    public function test_the_author_card_shows_the_english_biography_on_en(): void
    {
        $author = User::factory()->reporter()->create([
            'designation' => 'বিশেষ প্রতিনিধি', 'designation_en' => 'Special Correspondent',
            'bio' => 'বাংলা পরিচিতি', 'bio_en' => 'Writes from Dhaka.',
        ])->fresh();

        $bn = $this->article('bn', ['author_id' => $author->id]);
        $en = $this->article('en', ['author_id' => $author->id]);

        $this->get($bn->url)->assertOk()
            ->assertSee('বিশেষ প্রতিনিধি')->assertSee('বাংলা পরিচিতি')
            ->assertDontSee('Special Correspondent');

        $this->get($en->url)->assertOk()
            ->assertSee('Special Correspondent')->assertSee('Writes from Dhaka.')
            ->assertDontSee('বিশেষ প্রতিনিধি')->assertDontSee('বাংলা পরিচিতি');
    }

    /**
     * A job title and a biography fall back to **nothing**, which is the
     * opposite of a section or topic name and deliberate. A name has to
     * render or the page has no heading; neither of these is a heading, so an
     * English page with no card beats one with a Bangla job title under an
     * English headline.
     */
    public function test_a_reporter_with_no_english_details_gets_no_card_on_en(): void
    {
        $author = User::factory()->reporter()->create([
            'designation' => 'বিশেষ প্রতিনিধি', 'designation_en' => null,
            'bio' => 'বাংলা পরিচিতি', 'bio_en' => null,
        ])->fresh();

        $en = $this->article('en', ['author_id' => $author->id]);

        $html = $this->get($en->url)->assertOk()->getContent();

        $this->assertStringNotContainsString('বাংলা পরিচিতি', $html);
        $this->assertStringNotContainsString('বিশেষ প্রতিনিধি', $html);

        // The byline itself stays: a person's name is their name either way.
        $this->assertStringContainsString($author->name, $html);
    }

    /** One person, two pages — each listing that reporter's own edition. */
    public function test_an_author_page_exists_in_both_editions_and_is_scoped(): void
    {
        $author = User::factory()->reporter()->create([
            'designation_en' => 'Staff Correspondent', 'bio_en' => 'Writes from Dhaka.',
        ])->fresh();

        $bn = $this->article('bn', ['author_id' => $author->id, 'title' => 'বাংলা ফাঁস']);
        $en = $this->article('en', ['author_id' => $author->id, 'title' => 'The English one']);

        $this->get(route('author.show', $author))->assertOk()
            ->assertSee($bn->title, false)->assertDontSee($en->title, false);

        $this->get(route('en.author.show', $author))->assertOk()
            ->assertSee($en->title, false)->assertDontSee($bn->title, false)
            ->assertSee('Staff Correspondent');
    }

    public function test_an_english_byline_links_into_the_english_edition(): void
    {
        $author = User::factory()->reporter()->create(['bio_en' => 'Writes from Dhaka.'])->fresh();
        $en = $this->article('en', ['author_id' => $author->id]);

        $html = $this->get($en->url)->assertOk()->getContent();

        $this->assertStringContainsString(route('en.author.show', $author), $html);
        $this->assertStringNotContainsString(route('author.show', $author).'"', $html);
    }

    public function test_an_author_page_pairs_itself_with_hreflang(): void
    {
        $author = User::factory()->reporter()->create()->fresh();

        $html = $this->get(route('en.author.show', $author))->assertOk()->getContent();

        $this->assertStringContainsString('hreflang="en-BD"', $html);
        $this->assertMatchesRegularExpression(
            '/hreflang="x-default"\s*\n?\s*href="'.preg_quote(route('author.show', $author), '/').'"/',
            $html,
        );
    }

    /**
     * The structured data has to describe the person the *page* describes. A
     * `Person` whose `jobTitle` is Bangla on an English URL is the sort of
     * mismatch a search engine surfaces verbatim in a result.
     */
    public function test_the_author_json_ld_follows_the_edition(): void
    {
        $author = User::factory()->reporter()->create([
            'designation' => 'বিশেষ প্রতিনিধি', 'designation_en' => 'Special Correspondent',
            'bio' => 'বাংলা পরিচিতি', 'bio_en' => 'Writes from Dhaka.',
        ])->fresh();

        foreach ([
            route('author.show', $author) => ['বিশেষ প্রতিনিধি', 'বাংলা পরিচিতি'],
            route('en.author.show', $author) => ['Special Correspondent', 'Writes from Dhaka.'],
        ] as $url => [$title, $description]) {
            $html = $this->get($url)->assertOk()->getContent();

            preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
            $schema = json_decode(trim($m[1]), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($title, $schema['jobTitle']);
            $this->assertSame($description, $schema['description']);
            $this->assertSame($url, $schema['url']);
        }
    }

    /**
     * The third time a partial select has been caught missing an English
     * column, so it is asserted rather than remembered.
     *
     * `ArticleController` selects the author's four English-relevant columns
     * because the byline block prints both accessors. Under strict mode a
     * column left out is a `MissingAttributeException` — a 500 on the page,
     * not a lazy load — so rendering the page *is* the assertion.
     */
    public function test_the_article_select_carries_the_english_author_columns(): void
    {
        $author = User::factory()->reporter()->create([
            'designation' => 'বিশেষ প্রতিনিধি', 'designation_en' => 'Staff Correspondent',
            'bio' => 'বাংলা', 'bio_en' => 'Writes from Dhaka.',
        ])->fresh();

        [$bn, $en] = [
            $this->article('bn', ['author_id' => $author->id]),
            $this->article('en', ['author_id' => $author->id]),
        ];

        $this->get($en->url)->assertOk()->assertSee('Staff Correspondent');
        $this->get($bn->url)->assertOk()->assertSee('বিশেষ প্রতিনিধি');
    }

    /**
     * `/opinion` is the one listing that prints a byline's job title, and it
     * has no English edition — so `CARD_RELATIONS` deliberately carries
     * `designation` and not `designation_en`, and this is what says so. If
     * `/en/opinion` is ever added, that select needs the English column in
     * the same commit or the page is a 500.
     */
    public function test_the_card_select_deliberately_omits_the_english_designation(): void
    {
        $this->assertContains('author:id,name,slug,avatar,designation', \App\Services\ArticleQuery::CARD_RELATIONS);

        $this->assertArrayNotHasKey(
            'en.opinion', collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutesByName())->all(),
            'An English opinion listing exists, so CARD_RELATIONS must now carry designation_en.',
        );
    }

    // ── The translation files ────────────────────────────────────────────

    /**
     * `lang/bn.json` maps every key to itself, and it has to exist.
     *
     * `APP_FALLBACK_LOCALE` is `en` — it is what gives the framework's own
     * validation and auth messages somewhere to come from. With JSON
     * translations that fallback is a trap: with no `bn.json`, `__('আরও দেখুন')`
     * on the **Bangla** site would miss, fall through to `en.json`, and render
     * "See more" to a Bangla reader. The identity file stops the fallback ever
     * being consulted.
     *
     * The two files therefore have to hold exactly the same keys, and this is
     * the only thing that can notice when they stop.
     */
    public function test_the_two_translation_files_hold_the_same_keys(): void
    {
        $bn = json_decode(file_get_contents(lang_path('bn.json')), true, flags: JSON_THROW_ON_ERROR);
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($bn), array_keys($en), 'bn.json and en.json have drifted apart.');

        foreach ($bn as $key => $value) {
            $this->assertSame($key, $value, "bn.json must map every key to itself; [{$key}] does not.");
        }
    }

    /** Every `__()` key a template uses is actually in both files. */
    public function test_every_translated_string_in_a_view_has_an_entry(): void
    {
        $keys = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);

        $missing = [];
        $seen = 0;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/__\('((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($file->getPathname()), $found);

            foreach ($found[1] as $key) {
                $seen++;

                if (! array_key_exists($key, $keys)) {
                    $missing[] = basename($file->getPathname()).': '.$key;
                }
            }
        }

        // The control. A walk that found no files, or a regex that stopped
        // matching, would report zero missing keys — which reads exactly like
        // every string being translated.
        $this->assertGreaterThan(100, $seen, 'The view scan found almost no __() calls.');

        $this->assertEmpty($missing, "Untranslated keys:\n".implode("\n", $missing));
    }

    // ── The admin ────────────────────────────────────────────────────────

    public function test_an_editor_can_start_a_translation_and_it_is_idempotent(): void
    {
        $editor = User::factory()->editor()->create()->fresh();
        $bn = $this->article('bn');

        $first = $this->actingAs($editor)->post(route('admin.articles.translate', $bn));
        $translation = Article::where('translation_of', $bn->id)->firstOrFail();

        $first->assertRedirect(route('admin.articles.edit', $translation));
        $this->assertSame(Locale::ALTERNATE, $translation->locale);
        $this->assertSame(\App\Enums\ArticleStatus::Draft, $translation->status);
        $this->assertSame($bn->category_id, $translation->category_id);

        // Pressing it again opens the draft rather than making a second one:
        // two counterparts would make `counterpart()` ambiguous for ever.
        $this->actingAs($editor)->post(route('admin.articles.translate', $bn))
            ->assertRedirect(route('admin.articles.edit', $translation));

        $this->assertSame(1, Article::where('translation_of', $bn->id)->count());
    }

    /**
     * `exists:articles,id` on its own would accept an article in the *same*
     * edition — the graft this repository has already been bitten by on
     * `poll_options`. A Bangla story pointing at another Bangla story is not
     * a translation, and `counterpart()` would silently find nothing.
     */
    public function test_a_translation_cannot_point_at_the_same_edition(): void
    {
        $editor = User::factory()->editor()->create()->fresh();
        $otherBangla = $this->article('bn');

        // Deliberately *not* the `pair()` English article: that one already
        // points at its own original, and the pointer is one hop by design —
        // a chain would make "the other edition" ambiguous.
        $standaloneEnglish = $this->article('en');

        $payload = [
            'title' => 'যেকোনো শিরোনাম',
            'body' => '<p>দেহ</p>',
            'category_id' => $this->section->id,
            'type' => 'news',
            'status' => 'draft',
            'locale' => 'bn',
            'translation_of' => $otherBangla->id,
        ];

        $this->actingAs($editor)
            ->from(route('admin.articles.create'))
            ->post(route('admin.articles.store'), $payload)
            ->assertSessionHasErrors('translation_of');

        // The same field pointing at the other edition is accepted.
        $this->actingAs($editor)
            ->post(route('admin.articles.store'), ['translation_of' => $standaloneEnglish->id] + $payload)
            ->assertSessionHasNoErrors();

        // And a story that is already somebody's translation cannot be made
        // the target of a second hop.
        [, $alreadyLinked] = $this->pair();

        $this->actingAs($editor)
            ->from(route('admin.articles.create'))
            ->post(route('admin.articles.store'), ['translation_of' => $alreadyLinked->id] + $payload)
            ->assertSessionHasErrors('translation_of');
    }

    // ── Feeds ────────────────────────────────────────────────────────────

    /**
     * The two feeds are the same controller method and the same cached shape.
     * One cache key would serve whichever edition asked first to both — an
     * English feed of Bangla stories for ten minutes, with nothing saying so.
     */
    public function test_the_two_rss_feeds_do_not_share_a_cache_entry(): void
    {
        [$bn, $en] = $this->pair();

        // Bangla first, so its payload is the one that would have been reused.
        $this->get('/rss')->assertOk()->assertSee($bn->title, false);
        $this->get('/en/rss')->assertOk()
            ->assertSee($en->title, false)
            ->assertDontSee($bn->title, false);
    }

    public function test_each_sitemap_lists_only_its_own_edition(): void
    {
        [$bn, $en] = $this->pair();

        $this->get('/sitemap.xml')->assertOk()
            ->assertSee($bn->url, false)->assertDontSee($en->url, false);

        $this->get('/en/sitemap.xml')->assertOk()
            ->assertSee($en->url, false)->assertDontSee($bn->url, false);
    }
}
