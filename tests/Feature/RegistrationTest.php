<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** A complete, valid submission. Individual tests override one key. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'রফিকুল ইসলাম',
            'email' => 'rafiq@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'terms' => '1',
        ], $overrides);
    }

    public function test_the_form_renders(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_a_reader_can_register_and_is_logged_in(): void
    {
        $this->post('/register', $this->payload())
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'rafiq@example.com')->firstOrFail();

        $this->assertSame(UserRole::Reader, $user->role);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->email_verified_at, 'A new account must start unverified.');
        $this->assertAuthenticatedAs($user);
    }

    public function test_registering_sends_the_verification_email(): void
    {
        Notification::fake();

        $this->post('/register', $this->payload());

        Notification::assertSentTo(User::where('email', 'rafiq@example.com')->firstOrFail(), VerifyEmail::class);
    }

    public function test_the_password_is_hashed_not_stored_raw(): void
    {
        $this->post('/register', $this->payload());

        $stored = User::where('email', 'rafiq@example.com')->value('password');

        $this->assertNotSame('correct-horse-battery', $stored);
        $this->assertTrue(password_verify('correct-horse-battery', $stored));
    }

    // ── Phone, which readers type in Bangla ──────────────────────────────

    public function test_a_phone_in_bangla_digits_is_normalised_before_validation(): void
    {
        // ০১৭১২৩৪৫৬৭৮ — the regex is ASCII-only, so without
        // prepareForValidation() this submission is rejected outright.
        $this->post('/register', $this->payload(['phone' => '০১৭১২৩৪৫৬৭৮']))
            ->assertSessionHasNoErrors();

        $this->assertSame('01712345678', User::where('email', 'rafiq@example.com')->value('phone'));
    }

    public function test_a_malformed_phone_is_rejected(): void
    {
        // Not an 01[3-9] prefix.
        $this->post('/register', $this->payload(['phone' => '01212345678']))
            ->assertSessionHasErrors('phone');
    }

    public function test_a_phone_is_unique_across_both_digit_systems(): void
    {
        User::factory()->create(['phone' => '01712345678']);

        // The same number typed in Bangla must still collide, which it only
        // does because normalisation happens before the unique rule runs.
        $this->post('/register', $this->payload(['phone' => '০১৭১২৩৪৫৬৭৮']))
            ->assertSessionHasErrors('phone');

        $this->assertSame(1, User::where('phone', '01712345678')->count());
    }

    // ── Validation ───────────────────────────────────────────────────────

    public function test_an_email_is_lowercased(): void
    {
        $this->post('/register', $this->payload(['email' => '  RAFIQ@Example.COM ']));

        $this->assertDatabaseHas('users', ['email' => 'rafiq@example.com']);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'rafiq@example.com']);

        $this->post('/register', $this->payload())->assertSessionHasErrors('email');
    }

    public function test_the_terms_checkbox_is_required(): void
    {
        $this->post('/register', $this->payload(['terms' => '']))
            ->assertSessionHasErrors('terms');

        $this->assertGuest();
    }

    public function test_the_password_must_be_confirmed(): void
    {
        $this->post('/register', $this->payload(['password_confirmation' => 'something-else']))
            ->assertSessionHasErrors('password');
    }

    // ── Newsletter is a separate consent ─────────────────────────────────

    public function test_registering_alone_does_not_subscribe_to_the_newsletter(): void
    {
        $this->post('/register', $this->payload());

        $this->assertSame(0, NewsletterSubscriber::count());
    }

    public function test_ticking_the_box_subscribes(): void
    {
        $this->post('/register', $this->payload(['newsletter' => '1']));

        $subscriber = NewsletterSubscriber::where('email', 'rafiq@example.com')->firstOrFail();

        $this->assertSame(User::where('email', 'rafiq@example.com')->value('id'), $subscriber->user_id);
        $this->assertNull($subscriber->unsubscribed_at);
    }
}
