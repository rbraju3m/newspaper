<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * The stored avatar is written once and never refreshed, which is not what
     * the code looks like it does. `resolveUser()` returns at case 1 for every
     * returning reader, so the `updateOrCreate()` below it — whose whole
     * purpose is the update half, since the create half is what case 3 needs —
     * is only ever reached when no row with that (provider, provider_id) pair
     * exists. It therefore always creates and never updates.
     *
     * Pinned as it behaves rather than as it reads. The consequence is a
     * `social_accounts.avatar` that goes stale for ever, which is cosmetic;
     * the reason to record it is that the next person to read that
     * `updateOrCreate` will believe it refreshes something.
     */
    public function test_a_returning_sign_in_does_not_refresh_the_stored_avatar(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com', 'avatar' => 'https://cdn/old.jpg']);
        $this->get('/auth/google/callback');
        $this->post('/logout');

        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com', 'avatar' => 'https://cdn/new.jpg']);
        $this->get('/auth/google/callback');

        $this->assertSame('https://cdn/old.jpg', SocialAccount::firstOrFail()->avatar);
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
     * The asymmetry in `resolveUser()`, pinned as it behaves.
     *
     * Case 2 refuses to *link* an unverified provider email to a local
     * account, which is right. Case 3 will happily *create* one from the same
     * unverified address and stamp `email_verified_at` on it, because the
     * check is written `if ($user && ! verified)` and there is no `$user`.
     *
     * In practice neither shipped provider reaches this: Facebook only returns
     * addresses it has confirmed, and Google sends `email_verified`. It
     * matters the day a third provider is added, and it matters because the
     * account that results claims a verification nobody performed — enough to
     * squat an address its real owner has not signed up with yet.
     *
     * Not changed here. Refusing sign-up on an unverified address is a product
     * decision, and the smaller fix — create the account but do not stamp
     * `email_verified_at` — changes what an existing install's rows mean.
     */
    public function test_an_unverified_provider_email_still_creates_a_verified_account(): void
    {
        $this->fakeGoogle(['id' => '1001', 'email' => 'rafiq@example.com'], verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('home'));

        $user = User::firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(
            $user->email_verified_at,
            'Recorded as it behaves: the stamp is applied whether or not the provider verified the address.',
        );
    }

    /**
     * Account deletion is a soft delete — comments stay attributable — and it
     * leaves the `social_accounts` row behind, since `cascadeOnDelete` is a
     * database constraint and nothing was deleted at the database.
     *
     * So a reader who deletes their account and signs in with Google again
     * hits case 1, `$existing->user` resolves to null through the SoftDeletes
     * scope, and `resolveUser()` returns null — which the controller reports
     * as *"this social account has no email"*. It is the wrong message, and
     * registering by email as it suggests fails too, because the soft-deleted
     * row still holds the unique index on that address.
     *
     * Pinned as it behaves. The fix is a decision about what deletion means —
     * drop the social links on delete, or let a returning reader reclaim the
     * account — not a wording change.
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
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(0, User::count(), 'No replacement account is created either.');
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
