<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_toggling_gets_401_rather_than_a_redirect(): void
    {
        // The Alpine store flips optimistically and needs a status code it can
        // roll back on. A 302 to /login would be swallowed by fetch() and the
        // star would stay lit for an unsaved bookmark.
        $article = Article::factory()->create();

        $this->postJson("/account/bookmarks/{$article->id}")
            ->assertStatus(401)
            ->assertJsonStructure(['message']);
    }

    public function test_toggling_adds_then_removes(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)
            ->postJson("/account/bookmarks/{$article->id}")
            ->assertOk()
            ->assertJson(['bookmarked' => true]);

        $this->assertSame(1, $user->bookmarks()->count());

        $this->actingAs($user)
            ->postJson("/account/bookmarks/{$article->id}")
            ->assertOk()
            ->assertJson(['bookmarked' => false]);

        $this->assertSame(0, $user->bookmarks()->count());
    }

    public function test_bookmarks_are_per_reader(): void
    {
        $mine = User::factory()->create()->fresh();
        $theirs = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($mine)->postJson("/account/bookmarks/{$article->id}");

        $this->assertSame(1, $mine->bookmarks()->count());
        $this->assertSame(0, $theirs->bookmarks()->count());
    }

    public function test_the_bookmarks_page_lists_saved_articles(): void
    {
        $user = User::factory()->create()->fresh();
        $saved = Article::factory()->create(['published_at' => now()->subHour()]);
        $notSaved = Article::factory()->create(['published_at' => now()->subHour()]);

        $this->actingAs($user)->postJson("/account/bookmarks/{$saved->id}");

        $this->actingAs($user)
            ->get('/account/bookmarks')
            ->assertOk()
            ->assertSee($saved->title)
            ->assertDontSee($notSaved->title);
    }

    public function test_a_bookmark_can_be_removed_from_the_list(): void
    {
        $user = User::factory()->create()->fresh();
        $article = Article::factory()->create();

        $this->actingAs($user)->postJson("/account/bookmarks/{$article->id}");

        $this->actingAs($user)
            ->deleteJson("/account/bookmarks/{$article->id}")
            ->assertOk()
            ->assertJson(['bookmarked' => false]);

        $this->assertSame(0, $user->bookmarks()->count());
    }

    public function test_the_bookmarks_page_requires_a_login(): void
    {
        $this->get('/account/bookmarks')->assertRedirect('/login');
    }
}
