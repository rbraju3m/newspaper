<?php

namespace Tests\Feature;

use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public newsletter box.
 *
 * Note the standing gap this pins rather than papers over: `store()` tells the
 * reader to check their inbox, but the double opt-in mail is still a TODO, so
 * a subscriber created here is left unverified and nothing is ever sent. The
 * tests assert what the code actually does, so that finishing the sending side
 * shows up as a failure here rather than passing silently.
 *
 * The addresses here are deliberately not @example.com. The endpoint validates
 * `email:rfc,dns`, and egulias treats the RFC 2606 reserved domains
 * (example.com/.org/.net, .test, .invalid, .localhost) as undeliverable no
 * matter what DNS returns — so the usual test address is rejected before it
 * reaches the controller. That also means these four tests need working DNS:
 * roughly 150ms of real lookup each. The verify and unsubscribe tests build
 * their rows directly and touch neither.
 */
class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_subscribe(): void
    {
        $this->from('/')
            ->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com', 'name' => 'রফিক'])
            ->assertRedirect('/');

        $subscriber = NewsletterSubscriber::where('email', 'rafiq@gmail.com')->firstOrFail();

        $this->assertSame('রফিক', $subscriber->name);
        $this->assertNull($subscriber->unsubscribed_at);
        // Not yet confirmed: the opt-in mail is not built.
        $this->assertNull($subscriber->verified_at);
    }

    public function test_a_signed_in_reader_is_linked_to_their_account(): void
    {
        $user = User::factory()->create()->fresh();

        $this->actingAs($user)
            ->from('/')
            ->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com']);

        $this->assertSame($user->id, NewsletterSubscriber::where('email', 'rafiq@gmail.com')->value('user_id'));
    }

    public function test_subscribing_twice_does_not_duplicate_the_row(): void
    {
        $this->from('/')->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com']);
        $this->from('/')->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com']);

        $this->assertSame(1, NewsletterSubscriber::where('email', 'rafiq@gmail.com')->count());
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->from('/')
            ->post('/newsletter/subscribe', ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_a_verification_link_confirms_the_subscription(): void
    {
        $subscriber = NewsletterSubscriber::create([
            'email' => 'rafiq@gmail.com',
            'name' => 'রফিক',
        ]);

        $this->get("/newsletter/verify/{$subscriber->token}")
            ->assertRedirect(route('home'));

        $subscriber = $subscriber->fresh();

        $this->assertNotNull($subscriber->verified_at);
        $this->assertNull($subscriber->unsubscribed_at);
    }

    public function test_an_unsubscribe_link_stops_the_newsletter(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rafiq@gmail.com']);

        $this->get("/newsletter/unsubscribe/{$subscriber->token}")
            ->assertRedirect(route('home'));

        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
    }

    public function test_resubscribing_after_an_unsubscribe_works(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rafiq@gmail.com']);
        $this->get("/newsletter/unsubscribe/{$subscriber->token}");

        $this->from('/')->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com']);

        $this->assertNull($subscriber->fresh()->unsubscribed_at);
        $this->assertSame(1, NewsletterSubscriber::count());
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('/newsletter/verify/not-a-real-token')->assertNotFound();
        $this->get('/newsletter/unsubscribe/not-a-real-token')->assertNotFound();
    }
}
