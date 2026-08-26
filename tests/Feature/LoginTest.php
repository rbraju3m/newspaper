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

    public function test_the_session_id_is_rotated_on_login(): void
    {
        // Without this a session fixed before login stays valid after it.
        $this->reader();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'correct-horse-battery']);

        $this->assertNotSame($before, session()->getId());
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
