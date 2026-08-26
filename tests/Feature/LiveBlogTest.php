<?php

namespace Tests\Feature;

use App\Enums\ArticleStatus;
use App\Enums\ArticleType;
use App\Models\Article;
use App\Models\LiveEntry;
use App\Models\User;
use App\Services\HomepageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The live blog append path: the newsroom writing an update, and the reader's
 * browser picking it up.
 *
 * Two things make this worth its own file. It is the one editorial surface
 * where a write is expected every few minutes during a running story, so a
 * failure here is a failure in front of the largest audience the site ever
 * has. And it has two readers rather than one — the server-rendered timeline
 * and `payload()`, which the polling client injects with `x-html` — so the
 * shape of an entry is a contract, not an implementation detail.
 *
 * Sanitising is deliberately not re-covered here; `ContentSanitizeTest` owns
 * that for all three unescaped bodies. What is covered is everything around
 * it: ordering, the timestamp, authorisation, and the polling cursor.
 */
class LiveBlogTest extends TestCase
{
    use RefreshDatabase;

    private function liveArticle(?User $author = null): Article
    {
        return Article::factory()->create([
            'type' => ArticleType::Live,
            'status' => ArticleStatus::Published,
            'published_at' => now()->subHour(),
            'author_id' => $author?->id ?? User::factory()->reporter(),
        ]);
    }

    private function editor(): User
    {
        return User::factory()->editor()->create()->fresh();
    }

    private function entry(Article $article, array $attributes = []): LiveEntry
    {
        return LiveEntry::create([
            'article_id' => $article->id,
            'body' => '<p>আপডেট</p>',
            'published_at' => now(),
            ...$attributes,
        ]);
    }

    // ── Appending ────────────────────────────────────────────────────────

    public function test_an_editor_appends_an_update(): void
    {
        $article = $this->liveArticle();
        $editor = $this->editor();

        $this->actingAs($editor)
            ->post('/admin/articles/'.$article->id.'/live', [
                'headline' => 'গোল!',
                'body' => '<p>প্রথমার্ধের ৩৫ মিনিটে।</p>',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $entry = LiveEntry::where('article_id', $article->id)->sole();

        $this->assertSame('গোল!', $entry->headline);
        $this->assertSame($editor->id, $entry->user_id);
        $this->assertFalse($entry->is_pinned);
        $this->assertFalse($entry->is_key);
        $this->assertNotNull($entry->published_at);
    }

    public function test_the_body_is_required_and_says_so_in_bangla(): void
    {
        $article = $this->liveArticle();

        $this->actingAs($this->editor())
            ->post('/admin/articles/'.$article->id.'/live', ['headline' => 'শুধু শিরোনাম'])
            ->assertSessionHasErrors(['body' => 'আপডেটের বিবরণ লিখুন।']);

        $this->assertSame(0, LiveEntry::where('article_id', $article->id)->count());
    }

    public function test_the_pinned_and_key_checkboxes_are_carried(): void
    {
        $article = $this->liveArticle();

        $this->actingAs($this->editor())
            ->post('/admin/articles/'.$article->id.'/live', [
                'body' => '<p>মূল কথা</p>',
                'is_pinned' => '1',
                'is_key' => '1',
            ])->assertRedirect();

        $entry = LiveEntry::where('article_id', $article->id)->sole();

        $this->assertTrue($entry->is_pinned);
        $this->assertTrue($entry->is_key);
    }

    public function test_an_entry_defaults_to_now_but_an_explicit_time_is_kept(): void
    {
        $article = $this->liveArticle();
        $backdated = now()->subMinutes(20)->startOfMinute();

        $this->actingAs($this->editor())
            ->post('/admin/articles/'.$article->id.'/live', [
                'body' => '<p>আগের একটি মুহূর্ত</p>',
                'published_at' => $backdated->toDateTimeString(),
            ])->assertRedirect();

        $entry = LiveEntry::where('article_id', $article->id)->sole();

        $this->assertSame(
            $backdated->toDateTimeString(),
            $entry->published_at->toDateTimeString(),
            'A correction filed late must carry the time it happened, not the time it was typed.'
        );
    }

    public function test_appending_flushes_the_homepage_cache(): void
    {
        $article = $this->liveArticle();

        app(HomepageService::class)->build();
        $this->assertTrue(Cache::has(HomepageService::CACHE_KEY));

        $this->actingAs($this->editor())
            ->post('/admin/articles/'.$article->id.'/live', ['body' => '<p>নতুন</p>']);

        $this->assertFalse(Cache::has(HomepageService::CACHE_KEY));
    }

    public function test_a_non_live_article_has_no_timeline(): void
    {
        $article = Article::factory()->create(['type' => ArticleType::News]);
        $editor = $this->editor();

        $this->actingAs($editor)
            ->get('/admin/articles/'.$article->id.'/live')
            ->assertNotFound();

        $this->actingAs($editor)
            ->post('/admin/articles/'.$article->id.'/live', ['body' => '<p>x</p>'])
            ->assertNotFound();

        $this->assertSame(0, LiveEntry::where('article_id', $article->id)->count());
    }

    // ── Ordering: the timeline contract ──────────────────────────────────

    public function test_pinned_entries_sit_above_the_newest(): void
    {
        $article = $this->liveArticle();

        $old = $this->entry($article, ['published_at' => now()->subMinutes(30)]);
        $pinned = $this->entry($article, ['published_at' => now()->subMinutes(20), 'is_pinned' => true]);
        $newest = $this->entry($article, ['published_at' => now()]);

        $order = $article->liveEntries()->pluck('id')->all();

        $this->assertSame([$pinned->id, $newest->id, $old->id], $order);
    }

    // ── Editing and removing ─────────────────────────────────────────────

    public function test_an_entry_can_be_edited(): void
    {
        $article = $this->liveArticle();
        $entry = $this->entry($article, ['headline' => 'ভুল']);

        $this->actingAs($this->editor())
            ->put('/admin/live/'.$entry->id, [
                'headline' => 'সংশোধিত',
                'body' => '<p>নতুন বিবরণ</p>',
                'is_key' => '1',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $entry->refresh();

        $this->assertSame('সংশোধিত', $entry->headline);
        $this->assertTrue($entry->is_key);
    }

    public function test_editing_does_not_move_the_entry_in_the_timeline(): void
    {
        $article = $this->liveArticle();
        $at = now()->subMinutes(45)->startOfMinute();
        $entry = $this->entry($article, ['published_at' => $at]);

        $this->actingAs($this->editor())
            ->put('/admin/live/'.$entry->id, ['body' => '<p>একটি সংশোধনী</p>']);

        $this->assertSame(
            $at->toDateTimeString(),
            $entry->fresh()->published_at->toDateTimeString(),
            'Fixing a typo must not jump the entry to the top of a running blog.'
        );
    }

    public function test_an_entry_can_be_removed(): void
    {
        $article = $this->liveArticle();
        $entry = $this->entry($article);

        $this->actingAs($this->editor())
            ->delete('/admin/live/'.$entry->id)
            ->assertRedirect();

        $this->assertSame(0, LiveEntry::where('id', $entry->id)->count());
    }

    // ── Authorisation ────────────────────────────────────────────────────

    public function test_a_reporter_cannot_append_to_somebody_elses_live_blog(): void
    {
        $article = $this->liveArticle();
        $stranger = User::factory()->reporter()->create()->fresh();

        $this->actingAs($stranger)
            ->post('/admin/articles/'.$article->id.'/live', ['body' => '<p>x</p>'])
            ->assertForbidden();

        $this->assertSame(0, LiveEntry::where('article_id', $article->id)->count());
    }

    /**
     * A live blog is published by definition — that is what makes it live —
     * and ArticlePolicy::update() stops a reporter editing anything already
     * published. So running a live blog is an editor's job even on the
     * reporter's own story. Asserted rather than assumed, because it is a
     * consequence of the policy rather than a rule anybody wrote down.
     */
    public function test_a_reporter_cannot_append_to_their_own_published_live_blog(): void
    {
        $reporter = User::factory()->reporter()->create()->fresh();
        $article = $this->liveArticle($reporter);

        $this->actingAs($reporter)
            ->post('/admin/articles/'.$article->id.'/live', ['body' => '<p>x</p>'])
            ->assertForbidden();
    }

    public function test_a_reader_never_sees_the_timeline(): void
    {
        $article = $this->liveArticle();
        $entry = $this->entry($article);

        $reader = User::factory()->create()->fresh();

        $this->actingAs($reader)->get('/admin/articles/'.$article->id.'/live')->assertNotFound();
        $this->actingAs($reader)->put('/admin/live/'.$entry->id, ['body' => '<p>x</p>'])->assertNotFound();
        $this->actingAs($reader)->delete('/admin/live/'.$entry->id)->assertNotFound();
    }

    public function test_a_stranger_cannot_edit_or_delete_an_entry(): void
    {
        $article = $this->liveArticle();
        $entry = $this->entry($article, ['headline' => 'অক্ষত']);
        $stranger = User::factory()->reporter()->create()->fresh();

        $this->actingAs($stranger)->put('/admin/live/'.$entry->id, ['body' => '<p>x</p>'])->assertForbidden();
        $this->actingAs($stranger)->delete('/admin/live/'.$entry->id)->assertForbidden();

        $this->assertSame('অক্ষত', $entry->fresh()->headline);
    }

    // ── The polling endpoint ─────────────────────────────────────────────

    public function test_polling_returns_the_timeline_and_a_cursor(): void
    {
        $article = $this->liveArticle();
        $author = User::factory()->create(['name' => 'রফিক']);
        $entry = $this->entry($article, ['headline' => 'শুরু', 'user_id' => $author->id]);

        $this->getJson('/api/articles/'.$article->id.'/live')
            ->assertOk()
            ->assertJsonPath('latest', $entry->id)
            ->assertJsonPath('entries.0.id', $entry->id)
            ->assertJsonPath('entries.0.headline', 'শুরু')
            ->assertJsonPath('entries.0.author', 'রফিক')
            ->assertJsonPath('entries.0.pinned', false)
            ->assertJsonStructure(['latest', 'entries' => [['id', 'headline', 'body', 'image', 'time', 'ago', 'pinned', 'key', 'author', 'at']]]);
    }

    public function test_polling_with_a_cursor_returns_only_what_is_new(): void
    {
        $article = $this->liveArticle();
        $first = $this->entry($article, ['published_at' => now()->subMinutes(5)]);
        $second = $this->entry($article, ['published_at' => now()]);

        $this->getJson('/api/articles/'.$article->id.'/live?since='.$first->id)
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.id', $second->id)
            ->assertJsonPath('latest', $second->id);
    }

    public function test_a_quiet_minute_costs_an_empty_array(): void
    {
        $article = $this->liveArticle();
        $entry = $this->entry($article);

        $this->getJson('/api/articles/'.$article->id.'/live?since='.$entry->id)
            ->assertOk()
            ->assertJsonCount(0, 'entries')
            ->assertJsonPath('latest', $entry->id);
    }

    public function test_polling_an_unpublished_live_blog_is_a_404(): void
    {
        $article = Article::factory()->draft()->create(['type' => ArticleType::Live]);
        $this->entry($article);

        $this->getJson('/api/articles/'.$article->id.'/live')->assertNotFound();
    }

    public function test_polling_never_returns_another_articles_entries(): void
    {
        $article = $this->liveArticle();
        $other = $this->liveArticle();

        $mine = $this->entry($article, ['headline' => 'আমার']);
        $this->entry($other, ['headline' => 'অন্যের']);

        $this->getJson('/api/articles/'.$article->id.'/live')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.id', $mine->id);
    }

    /**
     * The cursor is `max(id)` over the whole timeline while `entries` is
     * capped at 30. A burst of more than 30 between two polls therefore
     * advances the client past updates it was never sent, and since the
     * client only ever asks for ids above its cursor, they are gone for good.
     */
    public function test_a_burst_larger_than_the_page_does_not_skip_entries(): void
    {
        $article = $this->liveArticle();

        $ids = [];
        for ($i = 0; $i < 35; $i++) {
            $ids[] = $this->entry($article, ['published_at' => now()->subMinutes(35 - $i)])->id;
        }

        $response = $this->getJson('/api/articles/'.$article->id.'/live?since='.($ids[0]))
            ->assertOk();

        $returned = $response->json('entries.*.id');
        $cursor = $response->json('latest');

        $missed = array_diff(array_slice($ids, 1), $returned);

        $this->assertEmpty(
            array_filter($missed, fn ($id) => $id < $cursor),
            'The cursor moved past entries the client was never sent, so the next poll will never ask for them.'
        );
    }

    /**
     * The reader is holding the timeline the page rendered, then 35 updates
     * land while the tab is in the background. Polling has to deliver all of
     * them, over as many round trips as that takes.
     */
    public function test_a_burst_drains_over_successive_polls(): void
    {
        $article = $this->liveArticle();

        $held = $this->entry($article, ['published_at' => now()->subHour()]);

        $ids = [];
        for ($i = 0; $i < 35; $i++) {
            $ids[] = $this->entry($article, ['published_at' => now()->subMinutes(35 - $i)])->id;
        }

        $cursor = $held->id;
        $seen = [];

        for ($poll = 0; $poll < 5; $poll++) {
            $response = $this->getJson('/api/articles/'.$article->id.'/live?since='.$cursor)->assertOk();

            $seen = array_merge($seen, $response->json('entries.*.id'));
            $next = $response->json('latest');

            if ($next === $cursor) {
                break;
            }

            $cursor = $next;
        }

        sort($seen);

        $this->assertSame($ids, array_values(array_unique($seen)), 'Every update in the burst reached the reader.');
        $this->assertSame(end($ids), $cursor);
    }

    /**
     * The cursor-less branch is a first load, not a backfill. The client is
     * seeded with `$entries->max('id')` from the server-rendered timeline —
     * see `components/article/live-timeline.blade.php` — so it only ever asks
     * without a cursor when the page rendered nothing, and then there is
     * nothing below the fold to miss. What it returns is the top of the
     * timeline, pinned above newest.
     */
    public function test_a_cursorless_poll_returns_the_top_of_the_timeline(): void
    {
        $article = $this->liveArticle();

        $oldest = $this->entry($article, ['published_at' => now()->subHours(2)]);
        $pinned = $this->entry($article, ['published_at' => now()->subHour(), 'is_pinned' => true]);
        $newest = $this->entry($article, ['published_at' => now()]);

        $response = $this->getJson('/api/articles/'.$article->id.'/live')->assertOk();

        $this->assertSame(
            [$pinned->id, $newest->id, $oldest->id],
            $response->json('entries.*.id')
        );
        $this->assertSame($newest->id, $response->json('latest'));
    }

    public function test_the_delta_comes_back_newest_first(): void
    {
        // The client prepends the array as it arrives, so the response order
        // is the display order.
        $article = $this->liveArticle();

        $base = $this->entry($article, ['published_at' => now()->subHour()]);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->entry($article, ['published_at' => now()->subMinutes(3 - $i)])->id;
        }

        $returned = $this->getJson('/api/articles/'.$article->id.'/live?since='.$base->id)
            ->assertOk()
            ->json('entries.*.id');

        $this->assertSame(array_reverse($ids), $returned);
    }
}
