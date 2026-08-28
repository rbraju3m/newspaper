<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

/**
 * Sign-in with Google and Facebook.
 *
 * The interesting half of this controller is `resolveUser()`, which decides
 * between three cases — a provider identity already seen, a local account with
 * the same email, and neither — and the second of those is an account-takeover
 * path if it is got wrong. Anyone who can set an address they do not own at a
 * provider that does not verify it could otherwise walk into the local account
 * holding that address.
 *
 * `Socialite::fake()` swaps the provider for one that returns a user we
 * construct, so nothing here talks to Google. The redirect test deliberately
 * does *not* fake: the thing being tested there is that the real driver is
 * reached and builds a real authorisation URL.
 */
class OAuthSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `guardProvider()` 404s an unconfigured provider, so every test that
        // expects to reach the controller body needs credentials present.
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
            'services.facebook.client_id' => 'facebook-client-id',
            'services.facebook.client_secret' => 'facebook-secret',
            'services.facebook.redirect' => 'http://localhost/auth/facebook/callback',
        ]);
    }

    // ── Which providers exist at all ─────────────────────────────────────

    public function test_an_unknown_provider_is_a_404_on_both_routes(): void
    {
        $this->get('/auth/twitter/redirect')->assertNotFound();
        $this->get('/auth/twitter/callback')->assertNotFound();
    }

    /**
     * An install that has not set up Google must 404 rather than 500.
     * `Socialite::driver('google')` with no client_id throws, so without this
     * guard the button would be a stack trace on every fresh deployment.
     */
    public function test_a_provider_with_no_credentials_is_a_404(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();

        // The one that is still configured keeps working.
        $this->get('/auth/facebook/redirect')->assertRedirect();
    }

    public function test_a_configured_provider_redirects_to_its_own_consent_screen(): void
    {
        $location = $this->get('/auth/google/redirect')
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth', $location);
        $this->assertStringContainsString('client_id=google-client-id', $location);
        $this->assertStringContainsString(urlencode('http://localhost/auth/google/callback'), $location);
    }

    /**
     * Both routes sit in the `guest` group. A reader who is already signed in
     * and lands on a stale OAuth link must not be able to re-run the callback
     * against their own session.
     */
    public function test_a_signed_in_reader_is_turned_away_from_both_routes(): void
    {
        $this->actingAs(User::factory()->create()->fresh());

        $this->get('/auth/google/redirect')->assertRedirect();
        $this->get('/auth/google/callback')->assertRedirect();
    }

    public function test_the_login_page_offers_only_the_providers_that_are_configured(): void
    {
        config(['services.facebook.client_id' => null]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('গুগল')
            ->assertDontSee('ফেসবুক');
    }

    public function test_the_login_page_says_nothing_about_social_login_when_nothing_is_configured(): void
    {
        config(['services.google.client_id' => null, 'services.facebook.client_id' => null]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('গুগল')
            ->assertDontSee('ফেসবুক')
            ->assertDontSee('অথবা');
    }

    // ── Case 3: nobody has seen this person before ───────────────────────

    public function test_a_first_sign_in_creates_a_verified_reader(): void
    {
        $this->fakeGoogle(['id' => '1001', 'name' => 'রফিকুল ইসলাম', 'email' => 'rafiq@example.com']);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $user = User::where('email', 'rafiq@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('রফিকুল ইসলাম', $user->name);
        $this->assertSame(UserRole::Reader, $user->role);
        $this->assertNotNull($user->email_verified_at, 'The provider proved the address; no second mail should be needed.');

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_id' => '1001',
        ]);
    }

    public function test_the_name_falls_back_to_the_local_part_of_the_email(): void
    {
        $this->fakeGoogle(['id' => '1001', 'name' => null, 'email' => 'rafiq@example.com']);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertSame('rafiq', User::where('email', 'rafiq@example.com')->firstOrFail()->name);
    }

    /**
     * Asserted on the stored string rather than with `assertDatabaseMissing`:
     * `users.email` is `utf8mb4_unicode_ci`, so MySQL matches the mixed-case
     * form against the lowercase row and the negative assertion can never
     * fail. Reading the value back is the only thing that actually sees it.
     */
    public function test_the_provider_email_is_stored_lowercased(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'Rafiq@Example.COM']);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertSame('rafiq@example.com', User::firstOrFail()->email);
    }

    // ── Case 1: this identity has signed in before ───────────────────────

    public function test_a_returning_identity_signs_the_same_reader_back_in(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com']);
        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $user = User::where('email', 'rafiq@example.com')->firstOrFail();
        $this->post('/logout');

        // Same provider id, but the profile has changed at Google since.
        $this->fakeGoogle([
            'id' => '1001',
            'name' => 'অন্য নাম',
            'email' => 'moved@example.com',
            'avatar' => 'https://lh3.googleusercontent.com/new',
        ]);
        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(1, User::count(), 'A returning identity must not create a second account.');
        $this->assertSame(1, SocialAccount::count());
    }

    // ── Case 2: the takeover path ────────────────────────────────────────

    public function test_a_verified_provider_email_links_to_the_existing_local_account(): void
    {
        $local = User::factory()->create(['email' => 'rafiq@example.com']);

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com'], verified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($local->fresh());
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('social_accounts', ['user_id' => $local->id, 'provider' => 'google']);
    }

    /**
     * The one that matters. A provider that has not verified the address is
     * asserting nothing, so linking on it would hand the local account to
     * whoever typed that address into their provider profile.
     */
    public function test_an_unverified_provider_email_cannot_take_over_a_local_account(): void
    {
        $local = User::factory()->create(['email' => 'rafiq@example.com']);

        $this->fakeGoogle(['id' => '9999', 'email' => 'rafiq@example.com'], verified: false);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(1, User::count(), 'The refusal must not create a shadow account either.');
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame('rafiq@example.com', $local->fresh()->email);
    }

    public function test_a_second_provider_links_to_the_same_reader(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com'], verified: true);
        $this->get('/auth/google/callback')->assertRedirect(route('home'));
        $this->post('/logout');

        $this->fakeProvider('facebook', ['id' => '2002', 'email' => 'rafiq@example.com'], verified: true);
        $this->get('/auth/facebook/callback')->assertRedirect(route('home'));

        $this->assertSame(1, User::count());
        $this->assertSame(
            ['facebook', 'google'],
            SocialAccount::orderBy('provider')->pluck('provider')->all(),
        );
    }

    public function test_a_returning_sign_in_refreshes_the_stored_avatar(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com', 'avatar' => 'https://cdn/old.jpg']);
        $this->get('/auth/google/callback');
        $this->post('/logout');

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com', 'avatar' => 'https://cdn/new.jpg']);
        $this->get('/auth/google/callback');

        $this->assertSame('https://cdn/new.jpg', SocialAccount::firstOrFail()->avatar);
    }

    /** An unchanged picture must not turn every sign-in into a write. */
    public function test_an_unchanged_avatar_is_not_rewritten(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com', 'avatar' => 'https://cdn/same.jpg']);
        $this->get('/auth/google/callback');
        $this->post('/logout');

        $touched = SocialAccount::firstOrFail()->updated_at;

        $this->travel(2)->minutes();
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com', 'avatar' => 'https://cdn/same.jpg']);
        $this->get('/auth/google/callback');

        $this->assertEquals($touched, SocialAccount::firstOrFail()->updated_at);
    }

    // ── Refusals ─────────────────────────────────────────────────────────

    public function test_a_provider_that_returns_no_email_is_refused(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => null]);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_provider_that_returns_no_id_is_refused(): void
    {
        $this->fakeGoogle(['id' => null, 'email' => 'rafiq@example.com']);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_suspended_reader_cannot_sign_in(): void
    {
        $suspended = User::factory()->create(['email' => 'rafiq@example.com', 'status' => 'suspended']);
        SocialAccount::create([
            'user_id' => $suspended->id, 'provider' => 'google', 'provider_id' => '1001',
        ]);

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com'], verified: true);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /**
     * A mismatched state parameter, a revoked consent, a network failure at
     * the token endpoint. All of them arrive as a throw from `->user()`, and
     * none of them may reach the reader as a stack trace.
     */
    public function test_a_failing_provider_returns_to_login_with_an_error(): void
    {
        Socialite::fake('google', fn () => throw new \RuntimeException('Invalid state.'));

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    /**
     * Case 2 refuses to *link* an unverified provider email to a local
     * account. Case 3 will still create one from that address — refusing
     * sign-up outright would lock out anyone whose provider simply does not
     * send the flag — but it must not claim a verification nobody performed,
     * or the account can sit on an address its real owner has not signed up
     * with yet.
     *
     * So it is created unstamped and goes through the ordinary mail, which is
     * what gates commenting.
     */
    public function test_an_unverified_provider_email_creates_an_unverified_account(): void
    {
        Notification::fake();

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com'], verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $user = User::firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_a_verified_provider_email_needs_no_verification_mail(): void
    {
        Notification::fake();

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com'], verified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $this->assertNotNull(User::firstOrFail()->email_verified_at);

        Notification::assertNothingSent();
    }

    /**
     * Account deletion is a soft delete — comments stay attributable — and it
     * leaves the `social_accounts` row behind, since `cascadeOnDelete` is a
     * database constraint and nothing was deleted at the database.
     *
     * Deletion stays permanent — the account page promises exactly that — so
     * signing in again must not resurrect anything. What it must do is say
     * so: this used to fall through to *"this social account has no email"*
     * and send the reader off to a registration that cannot succeed either,
     * because the soft-deleted row still holds the unique index on the
     * address.
     */
    public function test_a_deleted_reader_cannot_sign_back_in(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com']);
        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $user = User::firstOrFail();
        $this->post('/logout');
        $user->delete();

        $this->assertDatabaseHas('social_accounts', ['provider_id' => '1001']);

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com']);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['login' => 'এই অ্যাকাউন্টটি মুছে ফেলা হয়েছে। নতুন করে শুরু করতে অন্য একটি ইমেইল ব্যবহার করুন।']);

        $this->assertGuest();
        $this->assertSame(0, User::count(), 'No replacement account is created either.');
        $this->assertSame(1, User::withTrashed()->count(), 'And the deleted one stays deleted.');
    }

    /**
     * The same address arriving on a *different* provider identity — a new
     * Google account on a deleted reader's email. This one reaches case 2,
     * where the soft-deleted row holds the unique index, so creating a reader
     * would be a duplicate key and a 500 rather than a refusal.
     */
    public function test_a_new_identity_on_a_deleted_readers_address_is_refused(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com']);
        $this->get('/auth/google/callback');
        $this->post('/logout');
        User::firstOrFail()->delete();

        $this->fakeProvider('facebook', ['id' => '2002', 'email' => 'rafiq@example.com'], verified: true);

        $this->get('/auth/facebook/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    // ── Session ──────────────────────────────────────────────────────────

    /**
     * A session id fixed before sign-in must not still be valid after it.
     *
     * Two things make this harder to assert than it looks, and both of them
     * turn the obvious version of the test into one that cannot fail.
     *
     * Laravel's test client does not carry a response's cookies into the next
     * call — so two requests in a test are two unrelated sessions and their
     * ids always differ, rotation or not. Feeding the response's own (already
     * encrypted) session cookie back with `withUnencryptedCookie()` is what
     * continues the session. The file store is used so the session behaves
     * like a browser's and its payload survives the round trip; carrying the
     * cookie is the part that makes the assertion mean anything.
     *
     * So the test carries its own control: two plain requests on the carried
     * cookie must keep the *same* id. Without that assertion a harness that
     * silently failed to carry anything would report a rotation on every run,
     * which is indistinguishable from the thing working.
     *
     * What it proves is the property, not the line. `SessionGuard::login()`
     * already calls `migrate(true)`, so the controller's explicit
     * `session()->regenerate()` is belt-and-braces — removing it leaves the id
     * rotating anyway. The property is what a fixation attack cares about.
     */
    public function test_a_fixated_session_does_not_survive_sign_in(): void
    {
        config(['session.driver' => 'file']);

        $first = $this->get('/login')->assertOk();
        $fixated = $this->app['session']->getId();

        $this->continuingSession($first)->get('/login')->assertOk();

        $this->assertSame(
            $fixated,
            $this->app['session']->getId(),
            'Control failed: the session was not carried between requests, so this test '
            .'could not have detected a missing rotation either.',
        );

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com']);

        $this->continuingSession($first)->get('/auth/google/callback')
            ->assertRedirect(route('home'));

        $this->assertAuthenticated();
        $this->assertNotSame($fixated, $this->app['session']->getId());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Send this response's session cookie on the next request, so the two are
     * one session. The value is already encrypted, so it goes back through
     * `withUnencryptedCookie()` and `EncryptCookies` decrypts it exactly as it
     * would a browser's.
     */
    private function continuingSession(TestResponse $response): static
    {
        $name = config('session.cookie');

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === $name);

        $this->assertNotNull($cookie, 'The response set no session cookie to continue.');

        return $this->withUnencryptedCookie($name, $cookie->getValue());
    }

    private function fakeGoogle(array $attributes, bool $verified = true): void
    {
        $this->fakeProvider('google', $attributes, $verified);
    }

    private function fakeProvider(string $provider, array $attributes, bool $verified = true): void
    {
        $attributes += ['id' => '1001', 'name' => 'Test User', 'email' => null, 'avatar' => null];

        $social = new SocialiteUser;
        $social->map($attributes);

        // `providerEmailIsVerified()` reads the raw payload, which is where
        // Google puts `email_verified` and Facebook puts nothing at all.
        $social->setRaw($attributes + ['email_verified' => $verified]);

        Socialite::fake($provider, $social);
    }
}
