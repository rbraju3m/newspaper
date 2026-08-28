<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    private function reader(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'rafiq@example.com',
            'password' => 'correct-horse-battery',
        ], $attributes))->fresh();
    }

    public function test_the_account_page_renders(): void
    {
        $this->actingAs($this->reader())->get('/account')->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->get('/account/preferences')->assertRedirect('/login');
    }

    // ── Profile ──────────────────────────────────────────────────────────

    public function test_a_reader_can_update_their_name(): void
    {
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account')
            ->patch('/account', ['name' => 'রফিকুল ইসলাম খান', 'email' => 'rafiq@example.com'])
            ->assertSessionHasNoErrors();

        $this->assertSame('রফিকুল ইসলাম খান', $user->fresh()->name);
    }

    public function test_changing_the_email_invalidates_verification_and_resends(): void
    {
        // Otherwise a reader could move to an address they do not control and
        // keep a verified badge — and, with it, the ability to comment.
        Notification::fake();
        $user = $this->reader();

        $this->assertNotNull($user->email_verified_at);

        $this->actingAs($user)
            ->from('/account')
            ->patch('/account', ['name' => $user->name, 'email' => 'new@example.com'])
            ->assertSessionHasNoErrors();

        $user = $user->fresh();

        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_updating_without_changing_the_email_keeps_verification(): void
    {
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account')
            ->patch('/account', ['name' => 'অন্য নাম', 'email' => 'rafiq@example.com']);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_an_email_already_taken_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account')
            ->patch('/account', ['name' => $user->name, 'email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');

        $this->assertSame('rafiq@example.com', $user->fresh()->email);
    }

    // ── Password ─────────────────────────────────────────────────────────

    public function test_a_reader_can_change_their_password(): void
    {
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account')
            ->put('/account/password', [
                'current_password' => 'correct-horse-battery',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('a-brand-new-one', $user->fresh()->password));
    }

    public function test_the_current_password_is_required_to_change_it(): void
    {
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account')
            ->put('/account/password', [
                'current_password' => 'not-the-right-one',
                'password' => 'a-brand-new-one',
                'password_confirmation' => 'a-brand-new-one',
            ])
            ->assertSessionHasErrors('current_password', null, 'password');

        $this->assertTrue(Hash::check('correct-horse-battery', $user->fresh()->password));
    }

    // ── Deletion ─────────────────────────────────────────────────────────

    public function test_deleting_the_account_requires_the_password(): void
    {
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account')
            ->delete('/account', ['password' => 'wrong'])
            ->assertSessionHasErrors('password', null, 'delete');

        $this->assertAuthenticated();
        $this->assertNull($user->fresh()->deleted_at);
    }

    public function test_deleting_the_account_soft_deletes_and_logs_out(): void
    {
        // Soft delete, so existing comments stay attributable rather than
        // turning into orphans on published threads.
        $user = $this->reader();

        $this->actingAs($user)
            ->delete('/account', ['password' => 'correct-horse-battery'])
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNotNull(User::withTrashed()->find($user->id)->deleted_at);
    }

    /**
     * The claim the test above makes in a comment, actually asserted.
     *
     * The soft delete exists so a reader's published comments stay
     * attributable — but `Comment::user()` had no `withTrashed()`, so the
     * relation went null the moment somebody deleted their account, and
     * `comment/item.blade.php` reads `$comment->user->avatar_url` with no
     * guard. Every article page carrying one of their approved comments
     * answered **500**, and nothing pointed back at the account screen that
     * caused it.
     *
     * The article byline is not affected — `article.blade.php` and
     * `opinion-row` both guard `@if ($article->author)`. It was only the
     * comment thread.
     */
    public function test_a_deleted_readers_comments_still_render_on_the_article(): void
    {
        $user = $this->reader();
        $article = Article::factory()->create(['published_at' => now()->subHour()]);

        Comment::factory()->create([
            'article_id' => $article->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'body' => 'চমৎকার প্রতিবেদন।',
        ]);

        $this->get($article->url)->assertOk()->assertSee('চমৎকার প্রতিবেদন।');

        $this->actingAs($user)->delete('/account', ['password' => 'correct-horse-battery']);

        $this->get($article->url)
            ->assertOk()
            ->assertSee('চমৎকার প্রতিবেদন।')
            ->assertSee($user->name);
    }

    // ── Preferences ──────────────────────────────────────────────────────

    public function test_followed_categories_are_saved(): void
    {
        $user = $this->reader();
        $one = Category::factory()->create();
        $two = Category::factory()->create();

        $this->actingAs($user)
            ->from('/account/preferences')
            ->patch('/account/preferences', [
                'followed_categories' => [$one->id, $two->id],
                'breaking_alerts' => '1',
            ])
            ->assertSessionHasNoErrors();

        $preferences = $user->fresh()->preferences;

        $this->assertEqualsCanonicalizing([$one->id, $two->id], $preferences['followed_categories']);
        $this->assertTrue($preferences['breaking_alerts']);
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $this->actingAs($this->reader())
            ->from('/account/preferences')
            ->patch('/account/preferences', ['followed_categories' => [99999]])
            ->assertSessionHasErrors('followed_categories.0');
    }

    public function test_opting_into_the_newsletter_from_preferences_needs_no_second_confirmation(): void
    {
        // The account email is already verified, so asking the reader to
        // confirm it a second time would be theatre.
        $user = $this->reader();

        $this->actingAs($user)
            ->from('/account/preferences')
            ->patch('/account/preferences', ['newsletter' => '1', 'newsletter_frequency' => 'weekly']);

        $subscriber = NewsletterSubscriber::where('email', 'rafiq@example.com')->firstOrFail();

        $this->assertSame('weekly', $subscriber->frequency);
        $this->assertNotNull($subscriber->verified_at);
        $this->assertNull($subscriber->unsubscribed_at);
    }

    public function test_an_unverified_reader_opting_in_stays_unconfirmed(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'rafiq@example.com'])->fresh();

        $this->actingAs($user)
            ->from('/account/preferences')
            ->patch('/account/preferences', ['newsletter' => '1']);

        $this->assertNull(NewsletterSubscriber::where('email', 'rafiq@example.com')->value('verified_at'));
    }

    public function test_unticking_the_newsletter_unsubscribes(): void
    {
        $user = $this->reader();

        $this->actingAs($user)->from('/account/preferences')
            ->patch('/account/preferences', ['newsletter' => '1']);
        $this->assertNull(NewsletterSubscriber::where('email', 'rafiq@example.com')->value('unsubscribed_at'));

        $this->actingAs($user)->from('/account/preferences')
            ->patch('/account/preferences', []);

        $this->assertNotNull(NewsletterSubscriber::where('email', 'rafiq@example.com')->value('unsubscribed_at'));
    }
}
