<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * What the three feeds actually say, as opposed to whether they parse.
 *
 * `PublicRoutesTest` already proves each of them 200s and is well-formed XML.
 * That is the cheaper half of the contract and not the half that breaks: a
 * feed is read by machines nobody in the newsroom will ever hear from, so a
 * draft leaking into it, a story outside Google News's window, or a link
 * pointing at the wrong URL is silent for as long as it takes somebody
 * outside to notice.
 *
 * Every request here is made exactly once per test. All three responses are
 * cached — ten minutes, six hours and five minutes — so a second request in
 * the same test would answer from the first one's payload and assert nothing.
 */
class FeedContentsTest extends TestCase
{
    use RefreshDatabase;

    private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const NEWS_NS = 'http://www.google.com/schemas/sitemap-news/0.9';

    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    private const ATOM_NS = 'http://www.w3.org/2005/Atom';

    private Category $category;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create(['name' => 'খেলা', 'slug' => 'khela']);
        $this->author = User::factory()->reporter()->create(['name' => 'রফিকুল ইসলাম']);
    }

    // ── RSS ──────────────────────────────────────────────────────────────

    public function test_rss_channel_describes_the_publication(): void
    {
        $channel = $this->rss()->channel;

        $this->assertSame(config('site.name_bn'), (string) $channel->title);
        $this->assertSame(route('home'), (string) $channel->link);
        $this->assertSame(config('site.description'), (string) $channel->description);
        $this->assertSame('bn-BD', (string) $channel->language);

        // An aggregator that cannot find its own way back to the feed cannot
        // follow a move, and PubSubHubbub needs the self link to exist.
        $this->assertSame(
            route('feed.rss'),
            (string) $channel->children(self::ATOM_NS)->link->attributes()['href'],
        );
    }

    public function test_rss_item_carries_the_canonical_url_the_byline_and_the_excerpt(): void
    {
        $article = $this->publish([
            'title' => 'বাংলাদেশ সিরিজ জিতল',
            'excerpt' => 'শেষ ওভারে নাটকীয় জয়।',
        ]);

        $item = $this->rss()->channel->item[0];

        $this->assertSame($article->title, (string) $item->title);
        $this->assertSame($article->url, (string) $item->link);
        $this->assertSame($article->url, (string) $item->guid);
        $this->assertSame('true', (string) $item->guid->attributes()['isPermaLink']);
        $this->assertSame($article->published_at->toRfc2822String(), (string) $item->pubDate);
        $this->assertSame($this->category->name, (string) $item->category);
        $this->assertSame($this->author->name, (string) $item->children(self::DC_NS)->creator);
        $this->assertSame($article->excerpt, (string) $item->description);
    }

    /**
     * The link has to be the canonical article URL, materialised path and all.
     * A feed is where most syndicated traffic enters the site, so a link that
     * lands on the canonicalisation redirect costs a hop on every reader an
     * aggregator sends.
     */
    public function test_rss_links_resolve_without_a_redirect(): void
    {
        $child = Category::factory()->create([
            'parent_id' => $this->category->id,
            'name' => 'ক্রিকেট',
            'slug' => 'cricket',
        ]);

        $article = $this->publish(['category_id' => $child->id]);

        $link = (string) $this->rss()->channel->item[0]->link;

        $this->assertStringContainsString('/khela/cricket/', $link);
        $this->assertSame($article->url, $link);
        $this->get($link)->assertOk();
    }

    public function test_rss_carries_only_published_stories(): void
    {
        $live = $this->publish(['title' => 'প্রকাশিত খবর']);

        $draft = Article::factory()->draft()->for($this->category)
            ->create(['title' => 'খসড়া খবর']);

        $scheduled = Article::factory()->for($this->category)->create([
            'title' => 'নির্ধারিত খবর',
            'status' => ArticleStatus::Scheduled,
            'published_at' => now()->addHour(),
        ]);

        // A story pulled after publication has to leave the feed too, or the
        // retraction is the one copy that never propagates.
        $trashed = $this->publish(['title' => 'প্রত্যাহৃত খবর']);
        $trashed->delete();

        $titles = $this->itemValues($this->rss(), 'title');

        $this->assertSame([$live->title], $titles);
        $this->assertNotContains($draft->title, $titles);
        $this->assertNotContains($scheduled->title, $titles);
        $this->assertNotContains($trashed->title, $titles);
    }

    public function test_rss_is_ordered_newest_first(): void
    {
        $older = $this->publish(['title' => 'পুরোনো', 'published_at' => now()->subDays(2)]);
        $newest = $this->publish(['title' => 'সবচেয়ে নতুন', 'published_at' => now()->subMinutes(5)]);
        $middle = $this->publish(['title' => 'মাঝের', 'published_at' => now()->subDay()]);

        $this->assertSame(
            [$newest->title, $middle->title, $older->title],
            $this->itemValues($this->rss(), 'title'),
        );
    }

    public function test_rss_is_capped_at_forty_items_and_keeps_the_newest(): void
    {
        for ($i = 1; $i <= 42; $i++) {
            $this->publish([
                'title' => "খবর {$i}",
                'published_at' => now()->subMinutes(60 - $i),
            ]);
        }

        $titles = $this->itemValues($this->rss(), 'title');

        $this->assertCount(40, $titles);
        $this->assertSame('খবর 42', $titles[0]);
        $this->assertNotContains('খবর 1', $titles);
        $this->assertNotContains('খবর 2', $titles);
    }

    /**
     * Headlines are editor input and do contain ampersands and quotation
     * marks. One unescaped `&` is not a degraded feed — it is a parse error,
     * and an aggregator drops the whole document rather than the one item.
     */
    public function test_rss_escapes_markup_in_editor_written_text(): void
    {
        $article = $this->publish([
            'title' => 'ঢাকা & চট্টগ্রাম <উন্নয়ন> "পরিকল্পনা"',
            'excerpt' => 'এক & দুই <তিন>',
        ]);

        $body = $this->get('/rss')->assertOk()->getContent();

        $this->assertStringNotContainsString('<উন্নয়ন>', $body);

        $item = (new SimpleXMLElement($body))->channel->item[0];

        // Round-tripping is the assertion that matters: escaped on the way out,
        // identical on the way back in.
        $this->assertSame($article->title, (string) $item->title);
        $this->assertSame($article->excerpt, (string) $item->description);
    }

    /**
     * The enclosure is a typed pointer, and the type used to be the literal
     * string `image/jpeg` on every item. A reader that trusts it renders
     * nothing when the file is actually a PNG — which is not hypothetical:
     * `MediaSeeder` draws plates as PNG, and one article on the development
     * box is carrying one.
     */
    #[DataProvider('leadImages')]
    public function test_rss_enclosure_declares_the_real_image_type(string $image, string $expected): void
    {
        $this->publish(['image' => $image]);

        $enclosure = $this->rss()->channel->item[0]->enclosure;

        $this->assertSame($expected, (string) $enclosure->attributes()['type']);
    }

    public static function leadImages(): array
    {
        return [
            'jpg' => ['uploads/2026/08/khela/match.jpg', 'image/jpeg'],
            'jpeg' => ['uploads/2026/08/khela/match.jpeg', 'image/jpeg'],
            'png' => ['uploads/2026/08/khela/seed-khela-1.PNG', 'image/png'],
            'webp' => ['uploads/2026/08/khela/match.webp', 'image/webp'],
            // A legacy import: absolute, and with a query string after the
            // extension that a naive suffix check would swallow.
            'remote with query' => ['https://cdn.example.com/a/photo.png?v=2', 'image/png'],
            // Nothing recognisable is still an image; jpeg is the safe guess.
            'unknown' => ['uploads/2026/08/khela/photo', 'image/jpeg'],
        ];
    }

    public function test_rss_survives_a_story_with_no_byline_and_no_image(): void
    {
        $article = $this->publish(['author_id' => null, 'image' => null]);

        $item = $this->rss()->channel->item[0];

        $this->assertSame($article->title, (string) $item->title);
        $this->assertCount(0, $item->children(self::DC_NS)->creator);
        $this->assertCount(0, $item->enclosure);
    }

    // ── Sitemap ──────────────────────────────────────────────────────────

    public function test_sitemap_lists_the_homepage_every_active_category_and_published_articles(): void
    {
        $child = Category::factory()->create([
            'parent_id' => $this->category->id,
            'name' => 'ক্রিকেট',
            'slug' => 'cricket',
        ]);

        $hidden = Category::factory()->create(['slug' => 'gopon', 'is_active' => false]);

        $article = $this->publish();
        $draft = Article::factory()->draft()->for($this->category)->create();

        $locs = $this->sitemapLocs($this->get('/sitemap.xml')->assertOk()->getContent());

        $this->assertContains(route('home'), $locs);
        $this->assertContains(route('category.show', 'khela'), $locs);
        $this->assertContains(route('category.show', 'khela/cricket'), $locs);
        $this->assertContains($article->url, $locs);

        // A search engine handed a URL it is not allowed to index spends its
        // crawl budget learning that.
        $this->assertNotContains(route('category.show', $hidden->path), $locs);
        $this->assertNotContains($draft->url, $locs);
    }

    public function test_sitemap_lastmod_is_a_w3c_datetime_matching_the_row(): void
    {
        $article = $this->publish();

        $xml = new SimpleXMLElement($this->get('/sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', self::SITEMAP_NS);

        $lastmod = $xml->xpath(
            sprintf('//s:url[s:loc = "%s"]/s:lastmod', $article->url),
        );

        $this->assertCount(1, $lastmod);
        $this->assertSame($article->updated_at->toAtomString(), (string) $lastmod[0]);
    }

    /**
     * Every URL in a sitemap has to be absolute and on this host — a relative
     * `loc` invalidates the whole document.
     */
    public function test_sitemap_urls_are_absolute(): void
    {
        $this->publish();

        foreach ($this->sitemapLocs($this->get('/sitemap.xml')->assertOk()->getContent()) as $loc) {
            $this->assertStringStartsWith(config('app.url'), $loc);
        }
    }

    // ── Google News sitemap ──────────────────────────────────────────────

    public function test_news_sitemap_covers_only_the_last_forty_eight_hours(): void
    {
        $fresh = $this->publish(['title' => 'আজকের খবর', 'published_at' => now()->subHour()]);
        $edge = $this->publish(['title' => 'সীমানার খবর', 'published_at' => now()->subHours(47)]);
        $stale = $this->publish(['title' => 'পুরোনো খবর', 'published_at' => now()->subHours(49)]);

        $locs = $this->newsSitemapLocs();

        $this->assertContains($fresh->url, $locs);
        $this->assertContains($edge->url, $locs);

        // Google News rejects a sitemap holding anything older than the window
        // rather than ignoring the offending entries.
        $this->assertNotContains($stale->url, $locs);
    }

    public function test_news_sitemap_names_the_publication_the_language_and_the_headline(): void
    {
        $article = $this->publish([
            'title' => 'সংসদে বাজেট পাস',
            'published_at' => now()->subHour(),
        ]);

        $xml = new SimpleXMLElement($this->get('/news-sitemap.xml')->assertOk()->getContent());
        $xml->registerXPathNamespace('s', self::SITEMAP_NS);
        $xml->registerXPathNamespace('news', self::NEWS_NS);

        $this->assertSame([config('site.name_bn')], $this->strings($xml->xpath('//news:publication/news:name')));
        $this->assertSame([$article->locale], $this->strings($xml->xpath('//news:publication/news:language')));
        $this->assertSame([$article->title], $this->strings($xml->xpath('//news:title')));
        $this->assertSame(
            [$article->published_at->toAtomString()],
            $this->strings($xml->xpath('//news:publication_date')),
        );
    }

    public function test_news_sitemap_carries_only_published_stories(): void
    {
        $live = $this->publish(['published_at' => now()->subHour()]);

        Article::factory()->draft()->for($this->category)->create();

        $scheduled = Article::factory()->for($this->category)->create([
            'status' => ArticleStatus::Scheduled,
            'published_at' => now()->addHour(),
        ]);

        $this->assertSame([$live->url], $this->newsSitemapLocs());
        $this->assertNotContains($scheduled->url, $this->newsSitemapLocs());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function publish(array $attributes = []): Article
    {
        return Article::factory()
            ->for($this->category)
            ->for($this->author, 'author')
            ->create($attributes + ['published_at' => now()->subHour()]);
    }

    private function rss(): SimpleXMLElement
    {
        return new SimpleXMLElement($this->get('/rss')->assertOk()->getContent());
    }

    /** @return list<string> */
    private function itemValues(SimpleXMLElement $rss, string $element): array
    {
        return array_map(fn ($item) => (string) $item->{$element}, iterator_to_array($rss->channel->item, false));
    }

    /** @return list<string> */
    private function sitemapLocs(string $xml): array
    {
        $doc = new SimpleXMLElement($xml);
        $doc->registerXPathNamespace('s', self::SITEMAP_NS);

        return $this->strings($doc->xpath('//s:url/s:loc'));
    }

    /** @return list<string> */
    private function newsSitemapLocs(): array
    {
        return $this->sitemapLocs($this->get('/news-sitemap.xml')->assertOk()->getContent());
    }

    /**
     * @param  array<SimpleXMLElement>|false|null  $nodes
     * @return list<string>
     */
    private function strings(array|false|null $nodes): array
    {
        return array_map(fn ($n) => (string) $n, $nodes ?: []);
    }
}
