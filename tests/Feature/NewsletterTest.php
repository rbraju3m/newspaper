<?php

namespace Tests\Feature;

use App\Mail\NewsletterVerify;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public newsletter box.
 *
 * The double opt-in mail is now real, so `store()` telling the reader to check
 * their inbox is a promise the application keeps. `Mail::fake()` everywhere —
 * `.env` on the development box points MAIL_MAILER at a live Gmail account, and
 * a test that actually sent would send from a real address to whatever is
 * written below.
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

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_visitor_can_subscribe(): void
    {
        $this->from('/')
            ->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com', 'name' => 'রফিক'])
            ->assertRedirect('/');

        $subscriber = NewsletterSubscriber::where('email', 'rafiq@gmail.com')->firstOrFail();

        $this->assertSame('রফিক', $subscriber->name);
        $this->assertNull($subscriber->unsubscribed_at);

        // Unverified until they click, which is the whole point of double
        // opt-in: anybody can type a stranger's address into a form.
        $this->assertNull($subscriber->verified_at);

        Mail::assertSent(NewsletterVerify::class, fn (NewsletterVerify $mail) => $mail->hasTo('rafiq@gmail.com'));
    }

    /** Nothing to confirm, so nothing is sent — and no second mail on a re-post. */
    public function test_an_already_verified_address_is_not_mailed_again(): void
    {
        NewsletterSubscriber::create(['email' => 'rafiq@gmail.com'])
            ->forceFill(['verified_at' => now()])->save();

        $this->from('/')->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com']);

        Mail::assertNothingSent();
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

    /**
     * The link only asks. Every mail scanner between the sender and the reader
     * fetches the links in a message to check them, so a GET that unsubscribed
     * would unsubscribe people who never clicked anything.
     */
    public function test_the_unsubscribe_link_only_asks(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rafiq@gmail.com']);

        $this->get("/newsletter/unsubscribe/{$subscriber->token}")
            ->assertOk()
            ->assertSee('নিউজলেটার বন্ধ করবেন?');

        $this->assertNull($subscriber->fresh()->unsubscribed_at, 'Fetching the link must change nothing.');
    }

    public function test_confirming_stops_the_newsletter(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rafiq@gmail.com']);

        $this->post("/newsletter/unsubscribe/{$subscriber->token}")
            ->assertRedirect(route('home'));

        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
    }

    /**
     * RFC 8058: Gmail and Outlook post this from their own chrome with no
     * session and no CSRF token, and render nothing afterwards.
     */
    public function test_one_click_unsubscribe_needs_no_session(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rafiq@gmail.com']);

        $this->post("/newsletter/unsubscribe/{$subscriber->token}",
            ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
    }

    public function test_resubscribing_after_an_unsubscribe_works(): void
    {
        $subscriber = NewsletterSubscriber::create(['email' => 'rafiq@gmail.com']);
        $this->post("/newsletter/unsubscribe/{$subscriber->token}");

        $this->from('/')->post('/newsletter/subscribe', ['email' => 'rafiq@gmail.com']);

        $this->assertNull($subscriber->fresh()->unsubscribed_at);
        $this->assertSame(1, NewsletterSubscriber::count());
    }

    public function test_an_unknown_token_is_a_404(): void
    {
        $this->get('/newsletter/verify/not-a-real-token')->assertNotFound();
        $this->get('/newsletter/unsubscribe/not-a-real-token')->assertNotFound();
        $this->post('/newsletter/unsubscribe/not-a-real-token')->assertNotFound();
    }
}
