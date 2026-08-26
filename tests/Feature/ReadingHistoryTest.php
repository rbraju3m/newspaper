<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ReadingHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reading progress, posted by sendBeacon from the article page. The behaviour
 * that matters is that a reader who scrolls back up does not lose how far they
 * had got — the row keeps the high-water mark, not the last reading.
 */
class ReadingHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_creates_one_row(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)
            ->postJson("/articles/{$article->id}/read", ['progress' => 40, 'seconds' => 30])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $row = ReadingHistory::where('user_id', $user->id)->where('article_id', $article->id)->firstOrFail();

        $this->assertSame(40, $row->progress);
        $this->assertSame(30, $row->seconds);
    }

    public function test_revisiting_updates_the_same_row_rather_than_adding_another(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 40]);
        $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 80]);

        $this->assertSame(1, ReadingHistory::where('user_id', $user->id)->count());
        $this->assertSame(80, ReadingHistory::where('user_id', $user->id)->value('progress'));
    }

    public function test_progress_only_ever_moves_forward(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 80]);
        // Scrolling back up must not erase how far they actually got.
        $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 20]);

        $this->assertSame(80, ReadingHistory::where('user_id', $user->id)->value('progress'));
    }

    public function test_seconds_accumulate_across_visits(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 10, 'seconds' => 30]);
        $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 20, 'seconds' => 45]);

        $this->assertSame(75, ReadingHistory::where('user_id', $user->id)->value('seconds'));
    }

    public function test_an_out_of_range_progress_is_rejected(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)
            ->postJson("/articles/{$article->id}/read", ['progress' => 150])
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson("/articles/{$article->id}/read", ['progress' => -1])
            ->assertStatus(422);

        $this->assertSame(0, ReadingHistory::count());
    }

    public function test_the_history_page_lists_what_was_read(): void
    {
        $user = User::factory()->create()->fresh();
        $read = Article::factory()->create(['published_at' => now()->subHour()]);
        $unread = Article::factory()->create(['published_at' => now()->subHour()]);

        $this->actingAs($user)->postJson("/articles/{$read->id}/read", ['progress' => 50]);

        $this->actingAs($user)
            ->get('/account/history')
            ->assertOk()
            ->assertSee($read->title)
            ->assertDontSee($unread->title);
    }

    public function test_a_single_entry_can_be_removed(): void
    {
        $user = User::factory()->create()->fresh();
        $keep = Article::factory()->create();
        $drop = Article::factory()->create();

        $this->actingAs($user)->postJson("/articles/{$keep->id}/read", ['progress' => 50]);
        $this->actingAs($user)->postJson("/articles/{$drop->id}/read", ['progress' => 50]);

        $this->actingAs($user)
            ->from('/account/history')
            ->delete("/account/history/{$drop->id}")
            ->assertRedirect('/account/history');

        $this->assertSame(1, ReadingHistory::where('user_id', $user->id)->count());
        $this->assertSame($keep->id, ReadingHistory::where('user_id', $user->id)->value('article_id'));
    }

    public function test_the_whole_history_can_be_cleared(): void
    {
        $user = User::factory()->create()->fresh();
        $other = User::factory()->create()->fresh();

        foreach (Article::factory()->count(3)->create() as $article) {
            $this->actingAs($user)->postJson("/articles/{$article->id}/read", ['progress' => 50]);
        }

        $theirs = Article::factory()->create();
        $this->actingAs($other)->postJson("/articles/{$theirs->id}/read", ['progress' => 50]);

        $this->actingAs($user)
            ->from('/account/history')
            ->delete('/account/history')
            ->assertRedirect('/account/history');

        $this->assertSame(0, ReadingHistory::where('user_id', $user->id)->count());
        // And only theirs — clearing is not a global wipe.
        $this->assertSame(1, ReadingHistory::where('user_id', $other->id)->count());
    }

    public function test_tracking_requires_a_login(): void
    {
        $article = Article::factory()->create();

        $this->postJson("/articles/{$article->id}/read", ['progress' => 10])->assertStatus(401);
    }
}
