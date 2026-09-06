<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Models\Category;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `articles:translate` — the demo content that makes the English edition
 * visible.
 *
 * It is a seeding tool pointed at a live table, which is the shape that goes
 * wrong: `MediaSeeder` replaced every photograph on the site the first time it
 * was written, because "heal what is missing" and "rewrite everything" look
 * identical on an empty box. The three properties asserted here — inserts
 * only, idempotent, deterministic — are what keep this one from being that.
 */
class TranslateArticlesTest extends TestCase
{
    use RefreshDatabase;

    private function bangla(int $count = 3): void
    {
        Article::factory()->count($count)
            ->for(Category::factory())
            ->create(['locale' => Locale::DEFAULT]);
    }

    public function test_it_gives_published_bangla_stories_an_english_counterpart(): void
    {
        $this->bangla(3);

        $this->artisan('articles:translate --count=3')->assertSuccessful();

        $this->assertSame(3, Article::where('locale', Locale::ALTERNATE)->count());

        Article::where('locale', Locale::ALTERNATE)->each(function (Article $en) {
            $this->assertNotNull($en->translation_of);
            $this->assertSame(ArticleStatus::Published, $en->status);
        });
    }

    /**
     * Two counterparts for one story would make `counterpart()` ambiguous and
     * the second invisible to the switcher for ever, so a second run must
     * find nothing to do.
     */
    public function test_a_second_run_creates_nothing(): void
    {
        $this->bangla(3);

        $this->artisan('articles:translate --count=10')->assertSuccessful();
        $this->artisan('articles:translate --count=10')->assertSuccessful();

        $this->assertSame(3, Article::where('locale', Locale::ALTERNATE)->count());
    }

    /**
     * The same Bangla story must yield the same English one after the table
     * is rebuilt, or a screenshot and a comparison between two boxes stop
     * meaning anything. Keyed on the source id, which is what survives.
     */
    public function test_the_english_copy_is_derived_from_the_source_not_from_chance(): void
    {
        $source = Article::factory()->for(Category::factory())
            ->create(['locale' => Locale::DEFAULT]);

        $this->artisan('articles:translate --count=1')->assertSuccessful();
        $first = Article::where('translation_of', $source->id)->firstOrFail();

        // Same source id, rebuilt: the generator must produce the same words.
        $title = $first->title;
        $first->forceDelete();

        $this->artisan('articles:translate --count=1')->assertSuccessful();

        $this->assertSame($title, Article::where('translation_of', $source->id)->firstOrFail()->title);
    }

    public function test_it_never_edits_or_removes_an_existing_article(): void
    {
        $this->bangla(3);
        $before = Article::orderBy('id')->get(['id', 'title', 'body', 'status', 'locale'])->toArray();

        $this->artisan('articles:translate --count=3')->assertSuccessful();

        $after = Article::whereIn('id', array_column($before, 'id'))
            ->orderBy('id')->get(['id', 'title', 'body', 'status', 'locale'])->toArray();

        $this->assertEquals($before, $after, 'The command changed an article that already existed.');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->bangla(2);

        $this->artisan('articles:translate --dry-run')->assertSuccessful();

        $this->assertSame(0, Article::where('locale', Locale::ALTERNATE)->count());
    }

    /** `--draft` is what an editor wants; published is what a demo wants. */
    public function test_draft_counterparts_are_not_readable(): void
    {
        $this->bangla(1);

        $this->artisan('articles:translate --count=1 --draft')->assertSuccessful();

        $en = Article::where('locale', Locale::ALTERNATE)->firstOrFail();

        $this->assertSame(ArticleStatus::Draft, $en->status);

        // Asserted from the *Bangla* side, which is the side a reader is on.
        // The draft's own counterpart is the published original and is found
        // correctly — that is not the question.
        $this->assertNull(
            Article::findOrFail($en->translation_of)->counterpart(),
            'A draft translation must not be offered to a reader.',
        );
    }

    /**
     * `published_at` is guarded, so it has to be written with `forceFill()`.
     * Without it the English edition's order is the order the command
     * happened to run in rather than the order the news broke — every
     * counterpart stamped within the same second.
     */
    public function test_a_counterpart_keeps_the_original_publication_time(): void
    {
        $source = Article::factory()->for(Category::factory())->create([
            'locale' => Locale::DEFAULT,
            'published_at' => now()->subDays(9),
        ]);

        $this->artisan('articles:translate --count=1')->assertSuccessful();

        $this->assertTrue(
            Article::where('translation_of', $source->id)->firstOrFail()
                ->published_at->equalTo($source->published_at),
        );
    }

    public function test_a_draft_source_is_not_translated(): void
    {
        Article::factory()->draft()->for(Category::factory())->create(['locale' => Locale::DEFAULT]);

        $this->artisan('articles:translate')->assertSuccessful();

        $this->assertSame(0, Article::where('locale', Locale::ALTERNATE)->count());
    }

    /**
     * Two substrings from one written line only match the first Mockery
     * expectation, so this reads the buffer instead — the shape
     * `RedirectTest` was caught by.
     */
    public function test_it_reports_what_it_did(): void
    {
        $this->bangla(2);

        Artisan::call('articles:translate --count=2');
        $output = Artisan::output();

        $this->assertStringContainsString('Creating 2', $output);
        $this->assertStringContainsString('2 English counterpart(s) created.', $output);
    }

    public function test_it_says_so_when_there_is_nothing_left_to_do(): void
    {
        Artisan::call('articles:translate');

        $this->assertStringContainsString('already has an English counterpart', Artisan::output());
    }
}
