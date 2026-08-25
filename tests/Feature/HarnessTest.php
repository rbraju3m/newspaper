<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the test harness itself works. Not a test of application behaviour —
 * these assertions exist so that a broken harness fails loudly and specifically
 * instead of every later test failing for the same invisible reason.
 */
class HarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_against_the_mysql_test_database(): void
    {
        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('newspaper_test', $connection->getDatabaseName());
    }

    public function test_migrations_produce_the_mysql_fulltext_index(): void
    {
        // The reason the suite runs on MySQL at all: Article::search() uses
        // MATCH ... AGAINST, which no other driver implements. If this index is
        // missing the search scope silently degrades to LIKE and a passing
        // search test would prove nothing.
        $indexes = DB::select("SHOW INDEX FROM articles WHERE Key_name = 'articles_fulltext'");

        $this->assertNotEmpty($indexes, 'articles_fulltext index is missing.');
        $this->assertSame('FULLTEXT', $indexes[0]->Index_type);
    }

    public function test_factories_persist_through_the_model_layer(): void
    {
        $article = Article::factory()->create();

        $this->assertDatabaseHas('articles', ['id' => $article->id]);
        // The model generates the slug; a null here means model events did not
        // fire, which would make most later tests meaningless.
        $this->assertNotNull($article->slug);
    }

    public function test_strict_mode_is_active(): void
    {
        // CLAUDE.md leans on strict mode to catch lazy loading and guarded
        // mass assignment. If it were off under APP_ENV=testing, the suite
        // would pass on code that throws in development.
        $this->assertTrue(Model::preventsLazyLoading());
        $this->assertTrue(Model::preventsSilentlyDiscardingAttributes());
    }
}
