<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Article;
use App\Models\Category;
use App\Models\Poll;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The named rate limiters, and which routes wear them.
 *
 * Two things are being protected here and they pull in opposite directions.
 * A limit low enough to stop abuse is a limit an ordinary reader can hit by
 * using the site, and a 429 in front of a reader is a bug — so the assertions
 * below are as much about the headroom as about the ceiling.
 *
 * Throttling happens in middleware, which runs before validation. That is why
 * these tests can post deliberate rubbish: the request still counts against
 * the bucket, and the newsletter's `email:rfc,dns` rule never gets to make a
 * DNS lookup.
 *
 * One limiter is covered elsewhere rather than here: `throttle:ads`, on the
 * impression beacon, is asserted in `AdImpressionTest` beside the endpoint it
 * guards — the ids it carries are client-supplied, so the ceiling is part of
 * that feature's contract rather than a separate concern. Noted so this file
 * can still be read as the list of every named limiter.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** Fire a request `$times` over and assert every one of them got through. */
    private function hammer(int $times, callable $request): void
    {
        for ($i = 1; $i <= $times; $i++) {
            $response = $request();

            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "request {$i} of {$times} was throttled; the limit is too tight for ordinary use",
            );
        }
    }

    private function reader(): User
    {
        return User::factory()->create(['role' => UserRole::Reader])->fresh();
    }

    private function article(): Article
    {
        return Article::factory()->create(['category_id' => Category::factory()->create()->id]);
    }

    // -----------------------------------------------------------------------
    // Unauthenticated writes — the ones a stranger can reach
    // -----------------------------------------------------------------------

    public function test_newsletter_subscription_is_capped_per_hour(): void
    {
        $post = fn (): TestResponse => $this->post(route('newsletter.subscribe'), ['email' => 'not-an-email']);

        $this->hammer(5, $post);

        $post()->assertStatus(429);
    }

    public function test_poll_voting_is_capped_per_minute(): void
    {
        $poll = Poll::create(['question' => 'পরীক্ষামূলক প্রশ্ন', 'slug' => 'probe']);
        $post = fn (): TestResponse => $this->post(route('polls.vote', $poll), ['option_id' => 0]);

        $this->hammer(10, $post);

        $post()->assertStatus(429);
    }

    public function test_the_share_counter_cannot_be_hammered(): void
    {
        $article = $this->article();
        $post = fn (): TestResponse => $this->post(route('api.share', $article));

        $this->hammer(30, $post);

        $post()->assertStatus(429);
    }

    /**
     * Subscribing to push is a once-per-browser action. A reader toggling it
     * back and forth while deciding is the only honest way to reach ten.
     */
    public function test_push_subscription_cannot_be_hammered(): void
    {
        $post = fn (): TestResponse => $this->postJson(route('push.subscribe'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.uniqid(),
            'keys' => ['p256dh' => 'x', 'auth' => 'y'],
        ]);

        $this->hammer(10, $post);

        $post()->assertStatus(429);
    }

    /** FULLTEXT against a longText column is the most expensive public GET. */
    public function test_search_is_capped_per_minute(): void
    {
        $get = fn (): TestResponse => $this->get(route('search', ['q' => 'পরীক্ষা']));

        $this->hammer(30, $get);

        $get()->assertStatus(429);
    }

    /** The live blog polls every 20 seconds — three a minute per open tab. */
    public function test_polling_leaves_room_for_a_reader_with_tabs_open(): void
    {
        $this->hammer(60, fn (): TestResponse => $this->get(route('api.breaking')));

        $this->get(route('api.breaking'))->assertStatus(429);
    }

    // -----------------------------------------------------------------------
    // Reader writes
    // -----------------------------------------------------------------------

    public function test_account_mutations_are_capped_tightly(): void
    {
        $this->actingAs($this->reader());

        $put = fn (): TestResponse => $this->put(route('account.password.update'), []);

        $this->hammer(10, $put);

        $put()->assertStatus(429);
    }

    public function test_engagement_endpoints_leave_room_for_a_long_read(): void
    {
        $this->actingAs($this->reader());
        $article = $this->article();

        // The tracker posts on every 25% of new ground; sixty is many articles.
        $this->hammer(60, fn (): TestResponse => $this->post(route('history.track', $article), ['progress' => 50]));

        $this->post(route('history.track', $article), ['progress' => 50])->assertStatus(429);
    }

    // -----------------------------------------------------------------------
    // The property that matters most on a shared connection
    // -----------------------------------------------------------------------

    /**
     * A newsroom sits behind one NAT. Keying authenticated traffic by IP would
     * put the whole desk in one editor's bucket, so the first person to work
     * quickly would lock everyone else out.
     */
    public function test_authenticated_limits_are_per_account_not_per_address(): void
    {
        $first = $this->reader();
        $second = $this->reader();
        $article = $this->article();

        $this->actingAs($first);
        $this->hammer(60, fn (): TestResponse => $this->post(route('history.track', $article), ['progress' => 50]));
        $this->post(route('history.track', $article), ['progress' => 50])->assertStatus(429);

        // Same IP, different account: untouched allowance.
        $this->actingAs($second);
        $this->post(route('history.track', $article), ['progress' => 50])->assertStatus(200);
    }

    // -----------------------------------------------------------------------
    // What is deliberately not limited
    // -----------------------------------------------------------------------

    /**
     * Throttling logout would leave somebody unable to end their own session,
     * which protects nothing: it takes an authenticated request to reach and
     * destroys state rather than creating it.
     */
    public function test_logout_is_deliberately_not_throttled(): void
    {
        $reader = $this->reader();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($reader)->post(route('logout'))->assertRedirect();
        }
    }

    /** A busy editor must never meet the admin backstop. */
    public function test_the_admin_backstop_is_out_of_an_editors_way(): void
    {
        $editor = User::factory()->create(['role' => UserRole::Editor])->fresh();

        $this->actingAs($editor);
        $this->hammer(100, fn (): TestResponse => $this->get(route('admin.articles.index')));
    }
}
