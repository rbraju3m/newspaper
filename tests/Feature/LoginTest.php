<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * One field accepts either an email or a phone number, because a reader who
 * registered with a phone should not have to guess which box to use.
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function reader(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'email' => 'rafiq@example.com',
            'phone' => '01712345678',
            'password' => 'correct-horse-battery',
        ], $attributes))->fresh();
    }

    public function test_the_form_renders(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_a_reader_can_sign_in_with_their_email(): void
    {
        $user = $this->reader();

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_email_is_matched_case_insensitively(): void
    {
        $this->reader();

        $this->post('/login', ['login' => 'RAFIQ@Example.COM', 'password' => 'correct-horse-battery']);

        $this->assertAuthenticated();
    }

    public function test_a_reader_can_sign_in_with_their_phone(): void
    {
        $user = $this->reader();

        $this->post('/login', ['login' => '01712345678', 'password' => 'correct-horse-battery']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_reader_can_sign_in_with_a_phone_typed_in_bangla_digits(): void
    {
        // The stored column is ASCII. Without normalisation this is simply a
        // string that matches no row, and the reader is told their account
        // does not exist.
        $user = $this->reader();

        $this->post('/login', ['login' => '০১৭১২৩৪৫৬৭৮', 'password' => 'correct-horse-battery'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->reader();

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_an_unknown_identifier_is_refused(): void
    {
        $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_a_suspended_account_gets_no_session_even_with_the_right_password(): void
    {
        // Auth::attempt() succeeds here — the guard is what happens next.
        $this->reader(['status' => 'suspended']);

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /**
     * A session id fixed before login must not still be valid after it.
     *
     * This was written the obvious way and could not fail. Laravel's test
     * client does not carry a response's cookies into the next call, so the
     * two requests it compared were unrelated sessions whose ids always
     * differ — deleting the `regenerate()` it was guarding left it green.
     *
     * `continuingSession()` carries the cookie so the two requests are one
     * session, and the control below is what proves the harness did that: two
     * plain requests must keep the *same* id. Without it, a harness that
     * silently stopped carrying anything reports a rotation on every run,
     * which reads exactly like the thing working.
     *
     * What it pins is the property, not the line. `SessionGuard::login()`
     * calls `regenerate(true)` on its own, so `AuthenticatedSessionController`'s
     * explicit `session()->regenerate()` is belt-and-braces rather than the
     * only thing holding fixation off — and the property is what an attacker
     * cares about either way.
     */
    public function test_a_fixated_session_does_not_survive_login(): void
    {
        // The array store keeps nothing between requests, so a carried cookie
        // would name a session that no longer exists.
        config(['session.driver' => 'file']);

        $this->reader();

        $form = $this->get('/login')->assertOk();
        $fixated = $this->app['session']->getId();

        $this->continuingSession($form)->get('/login')->assertOk();

        $this->assertSame(
            $fixated,
            $this->app['session']->getId(),
            'Control failed: the session was not carried between requests, so this test '
            .'could not have detected a missing rotation either.',
        );

        $this->continuingSession($form)->post('/login', [
            'login' => 'rafiq@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->assertAuthenticated();
        $this->assertNotSame($fixated, $this->app['session']->getId());
    }

    public function test_login_is_rate_limited_after_five_failures(): void
    {
        Event::fake([Lockout::class]);
        $this->reader();

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'wrong']);
        }

        // The sixth is refused before the credentials are even checked, so a
        // correct password does not get through either.
        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
        Event::assertDispatched(Lockout::class);
    }

    public function test_the_throttle_is_keyed_per_identifier_so_one_reader_cannot_lock_out_another(): void
    {
        $this->reader();
        User::factory()->create(['email' => 'other@example.com', 'password' => 'correct-horse-battery']);

        foreach (range(1, 5) as $ignored) {
            $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['login' => 'other@example.com', 'password' => 'correct-horse-battery']);

        $this->assertAuthenticated();
    }

    public function test_a_reader_can_log_out(): void
    {
        $this->actingAs($this->reader())
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_a_redirect_parameter_is_honoured_after_login(): void
    {
        // Views link to /login?redirect=… so a reader who clicked "bookmark"
        // lands back on the story.
        $this->reader();

        $this->get('/login?redirect=/latest');

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/latest');
    }
}
