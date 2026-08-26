<?php

namespace Tests\Feature;

use App\Enums\HomeBlockType;
use App\Models\Article;
use App\Models\Category;
use App\Models\HomeBlock;
use App\Models\Page;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every public URL answers, with content present.
 *
 * This is the coverage that used to live in an ad-hoc script: it catches the
 * class of breakage that a lint pass cannot — a Blade template referencing an
 * attribute the controller did not eager-load, a strict-mode lazy-load throw,
 * a route shadowed by the catch-all.
 *
 * The fixtures are deliberately built once per test rather than seeded: the
 * demo seeders are slow and their content drifts, and a route test that
 * depends on 374 seeded articles hides which row it actually needed.
 */
class PublicRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create(['name' => 'khela', 'slug' => 'khela']);

        $this->article = Article::factory()
            ->for($this->category)
            ->create(['published_at' => now()->subHour()]);

        // The front page is assembled entirely from editor-configured blocks —
        // with none, it legitimately renders empty. Give it a hero so the
        // render path is actually exercised.
        HomeBlock::create([
            'type' => HomeBlockType::Hero,
            'title' => 'প্রধান খবর',
            'limit' => 5,
            'position' => 0,
            'is_active' => true,
            'column' => 'main',
        ]);

        // The assembled page is cached for 120s. Tests each get a fresh array
        // store, but flushing states the dependency rather than relying on it.
        HomepageService::flush();
    }

    public function test_homepage_renders_its_configured_blocks(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee($this->article->title);
    }

    /**
     * The fixed-prefix listings. Each has to be matched before the catch-all
     * `/{category}` route, so a 200 here also proves route order survived.
     */
    public static function listingRoutes(): array
    {
        return [
            'latest' => ['/latest'],
            'popular' => ['/popular'],
            'opinion' => ['/opinion'],
            'video hub' => ['/video'],
            'photo hub' => ['/photo'],
            'archive' => ['/archive'],
            'search' => ['/search'],
            'epaper hub' => ['/epaper'],
            'live' => ['/live'],
            'offline' => ['/offline'],
        ];
    }

    #[DataProvider('listingRoutes')]
    public function test_listing_route_renders(string $path): void
    {
        $this->get($path)->assertOk();
    }

    public function test_category_page_renders_its_articles(): void
    {
        $this->get('/khela')
            ->assertOk()
            ->assertSee($this->article->title);
    }

    public function test_nested_category_path_resolves(): void
    {
        $child = Category::factory()->create([
            'parent_id' => $this->category->id,
            'name' => 'cricket',
            'slug' => 'cricket',
        ]);

        // The materialised path is what the URL uses, not the bare slug.
        $this->assertSame('khela/cricket', $child->path);

        $this->get('/khela/cricket')->assertOk();
    }

    public function test_article_page_renders(): void
    {
        $this->get($this->article->url)
            ->assertOk()
            ->assertSee($this->article->title);
    }

    public function test_article_url_canonicalises_a_stale_slug(): void
    {
        // A story that has been renamed, or moved section, must still resolve —
        // but exactly once, to one indexable URL.
        $stale = '/khela/'.$this->article->id.'/an-old-slug';

        $this->get($stale)->assertRedirect($this->article->url);
    }

    public function test_draft_articles_are_not_public(): void
    {
        $draft = Article::factory()->draft()->for($this->category)->create();

        $this->get($draft->url)->assertNotFound();
    }

    public function test_staff_may_preview_an_unpublished_article(): void
    {
        $draft = Article::factory()->draft()->for($this->category)->create();
        $editor = User::factory()->editor()->create()->fresh();

        $this->actingAs($editor)->get($draft->url)->assertOk();
    }

    public function test_topic_tag_and_author_pages_render(): void
    {
        $topic = Topic::factory()->create();
        $tag = Tag::factory()->create();
        $this->article->topics()->attach($topic);
        $this->article->tags()->attach($tag);

        $this->get("/topic/{$topic->slug}")->assertOk()->assertSee($this->article->title);
        $this->get("/tag/{$tag->slug}")->assertOk()->assertSee($this->article->title);

        $author = $this->article->author;
        $this->get("/author/{$author->slug}")->assertOk()->assertSee($this->article->title);
    }

    public function test_static_page_renders_and_inactive_one_does_not(): void
    {
        $page = Page::factory()->create();
        $hidden = Page::factory()->inactive()->create();

        $this->get("/page/{$page->slug}")->assertOk()->assertSee($page->title);
        $this->get("/page/{$hidden->slug}")->assertNotFound();
    }

    public function test_video_detail_renders(): void
    {
        $video = Article::factory()->video()->for($this->category)->create([
            'published_at' => now()->subHour(),
        ]);

        $this->get("/video/{$video->id}")->assertOk()->assertSee($video->title);
    }

    public function test_feeds_are_well_formed_xml(): void
    {
        foreach (['/rss', '/sitemap.xml', '/news-sitemap.xml'] as $path) {
            $response = $this->get($path)->assertOk();

            // A feed that 200s with malformed XML is worse than one that 500s:
            // aggregators drop it silently.
            $this->assertNotFalse(
                simplexml_load_string($response->getContent()),
                "{$path} did not return parseable XML."
            );
        }
    }

    /**
     * `@section('name', $value)` is only an inline section when $value is not
     * null. Blade compares with `===`, so a null — an empty meta_description,
     * a topic with no blurb, an author with no bio — silently switches it to
     * the block form: `startSection()` opens an output buffer and waits for an
     * `@endsection` that the template never writes.
     *
     * The page still renders, which is why this survived manual checking. What
     * it leaves behind is an unbalanced output buffer on every such request,
     * and a description section that swallows whatever is emitted after it.
     *
     * Asserting on the buffer level is the only cheap way to see it.
     */
    public function test_pages_with_empty_optional_text_do_not_leak_an_output_buffer(): void
    {
        $topic = Topic::factory()->create(['description' => null]);
        $author = User::factory()->reporter()->create(['bio' => null])->fresh();
        $page = Page::factory()->create(['meta_description' => null]);

        $bare = Article::factory()->for($this->category)->create([
            'published_at' => now()->subHour(),
            'meta_description' => null,
            'excerpt' => null,
        ]);

        $paths = [
            "/topic/{$topic->slug}",
            "/author/{$author->slug}",
            "/page/{$page->slug}",
            $bare->url,
        ];

        foreach ($paths as $path) {
            $before = ob_get_level();
            $this->get($path)->assertOk();

            $this->assertSame(
                $before,
                ob_get_level(),
                "{$path} left an output buffer open — an @section given null content."
            );
        }
    }

    public function test_unknown_category_is_a_404_not_a_500(): void
    {
        // The catch-all matches everything, so a miss has to be turned into a
        // 404 by the controller rather than throwing on a null model.
        $this->get('/no-such-section')->assertNotFound();
    }
}
