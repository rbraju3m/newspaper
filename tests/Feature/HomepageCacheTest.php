<?php

namespace Tests\Feature;

use App\Enums\HomeBlockType;
use App\Models\Article;
use App\Models\Category;
use App\Models\HomeBlock;
use App\Models\Media;
use App\Models\User;
use App\Services\HomepageService;
use App\Support\PackedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the front page costs to build, and what it costs to read back.
 *
 * Both halves of this failed silently, which is why they are pinned. Neither
 * a wrong answer nor an error — just a page that took four times the queries
 * and half a megabyte of MySQL traffic to produce the identical bytes.
 *
 * The assertions are deliberately about *growth* rather than absolute totals.
 * A fixed budget ("no more than 40 queries") turns into a chore that gets
 * bumped every time a block is added; what actually matters is that the per-
 * block cost stays flat, so that is what is measured — build the same page
 * twice, once with three times the blocks, and require the eager loads not to
 * follow.
 */
class HomepageCacheTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the SQL of every query one build issued */
    private function queriesForBuild(): array
    {
        Cache::forget(HomepageService::CACHE_KEY);

        DB::flushQueryLog();
        DB::enableQueryLog();

        (new HomepageService)->build();

        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $log;
    }

    /**
     * Queries that loaded a card's category, byline or lead image.
     *
     * Matched on the key-set shape an eager load has — `<table>`.`id` in (…) —
     * rather than the table name alone, because `inCategory()` also selects
     * from `categories`, and so does the `orWhereHas` inside the article query
     * itself.
     */
    private function relationQueries(array $log): array
    {
        return array_values(array_filter($log, fn (string $sql) => (bool) preg_match(
            '/`(categories|users|media)`\.`id` in \(/',
            $sql,
        ) && str_contains($sql, 'from `')
            && ! str_contains($sql, 'from `articles`')));
    }

    /**
     * @param  int  $sections  how many category blocks to put on the page
     */
    private function layout(int $sections): void
    {
        HomeBlock::query()->delete();

        $author = User::factory()->reporter()->create();

        for ($i = 0; $i < $sections; $i++) {
            $category = Category::factory()->create();
            $media = Media::factory()->create();

            Article::factory()
                ->count(3)
                ->for($category)
                ->for($author, 'author')
                ->create(['image_id' => $media->id]);

            HomeBlock::create([
                'type' => HomeBlockType::CategoryGrid,
                'limit' => 3,
                'column' => 'main',
                'position' => $i,
                'category_id' => $category->id,
                'is_active' => true,
            ]);
        }
    }

    // ── The build ────────────────────────────────────────────────────────

    public function test_card_relations_are_loaded_once_for_the_whole_page_not_once_per_block(): void
    {
        $this->layout(2);
        $small = $this->relationQueries($this->queriesForBuild());

        $this->layout(6);
        $large = $this->relationQueries($this->queriesForBuild());

        // Three relations — category, author, featuredImage — in one pass over
        // every article on the page, however many blocks produced them, plus
        // the block list's own `with('category')`, which is also one query.
        $this->assertLessThanOrEqual(4, count($large));
        $this->assertSame(
            count($small),
            count($large),
            'Tripling the blocks changed the number of eager-load queries, so each block is loading its own again.',
        );
    }

    public function test_every_card_comes_out_of_the_build_with_its_relations_already_loaded(): void
    {
        $this->layout(3);
        Cache::forget(HomepageService::CACHE_KEY);

        $blocks = (new HomepageService)->build()['main'];

        $articles = $blocks->flatMap(fn (array $b) => $b['data'] instanceof \Illuminate\Support\Collection ? $b['data'] : []);

        $this->assertNotEmpty($articles);

        foreach ($articles as $article) {
            // Strict mode is on: an unloaded relation here is a 500 on the
            // front page, not a slow render.
            $this->assertTrue($article->relationLoaded('category'));
            $this->assertTrue($article->relationLoaded('author'));
            $this->assertTrue($article->relationLoaded('featuredImage'));
        }
    }

    public function test_a_second_build_is_served_from_cache_without_touching_the_content_tables(): void
    {
        $this->layout(3);
        Cache::forget(HomepageService::CACHE_KEY);
        (new HomepageService)->build();

        DB::flushQueryLog();
        DB::enableQueryLog();
        (new HomepageService)->build();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame([], array_values(array_filter(
            $log,
            fn (string $sql) => str_contains($sql, '`articles`') || str_contains($sql, '`home_blocks`'),
        )));
    }

    // ── The stored payload ───────────────────────────────────────────────

    public function test_the_cached_front_page_is_stored_compressed(): void
    {
        $this->layout(6);
        Cache::forget(HomepageService::CACHE_KEY);

        $built = (new HomepageService)->build();
        $stored = Cache::get(HomepageService::CACHE_KEY);

        // Not assertIsString(): on failure it dumps the entire unpacked page
        // into the diff, which buries the one fact that matters.
        $this->assertTrue(
            is_string($stored),
            'The front page is cached as a '.get_debug_type($stored).', so it is not going through PackedCache.',
        );
        $this->assertLessThan(
            strlen(serialize($built)) / 2,
            strlen($stored),
            'The front page is being cached at close to its serialized size, so it is not being packed.',
        );
    }

    public function test_the_packed_payload_round_trips_the_model_graph(): void
    {
        $this->layout(3);
        Cache::forget(HomepageService::CACHE_KEY);

        $first = (new HomepageService)->build();
        $second = (new HomepageService)->build();   // this one comes back through unpack()

        $this->assertEquals(
            $first['main']->flatMap(fn (array $b) => $b['data'])->pluck('id')->all(),
            $second['main']->flatMap(fn (array $b) => $b['data'])->pluck('id')->all(),
        );

        $article = $second['main']->flatMap(fn (array $b) => $b['data'])->first();

        $this->assertTrue($article->relationLoaded('category'));
        $this->assertNotNull($article->category->name);
    }

    /**
     * The two ways a stored entry can be unreadable, both of which have to cost
     * a rebuild rather than the page.
     *
     * The second is not hypothetical: it is what a rollback looks like, and the
     * reverse of it is every deploy. An entry written by the build before this
     * one is a plain array where a packed string is expected.
     */
    public static function unreadableEntries(): array
    {
        return [
            'truncated' => ['not-valid-base64-$$$'],
            'written by an older build' => [['main' => [], 'sidebar' => []]],
        ];
    }

    #[DataProvider('unreadableEntries')]
    public function test_an_unreadable_cache_entry_rebuilds_rather_than_failing(mixed $stored): void
    {
        $this->layout(2);
        Cache::forever(HomepageService::CACHE_KEY, $stored);

        $built = (new HomepageService)->build();

        $this->assertArrayHasKey('main', $built);
        $this->assertCount(2, $built['main']);
    }

    public function test_packed_cache_preserves_a_cached_null(): void
    {
        $calls = 0;
        $build = function () use (&$calls) {
            $calls++;

            return null;
        };

        $this->assertNull(PackedCache::remember('packed.null', 60, $build));
        $this->assertNull(PackedCache::remember('packed.null', 60, $build));

        $this->assertSame(1, $calls, 'A cached null was treated as a miss and rebuilt.');
    }
}
