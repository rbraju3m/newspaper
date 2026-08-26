<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_request_form_renders(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_requesting_a_link_sends_the_notification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'rafiq@example.com']);

        $this->post('/forgot-password', ['email' => 'rafiq@example.com'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reset_form_renders_with_a_token(): void
    {
        $this->get('/reset-password/some-token')->assertOk();
    }

    public function test_a_reader_can_reset_their_password_and_sign_in_with_it(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'rafiq@example.com',
            'password' => 'the-old-one',
        ]);

        $this->post('/forgot-password', ['email' => 'rafiq@example.com']);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'rafiq@example.com',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('a-brand-new-one', $user->fresh()->password));

        // The point of the whole exercise: the new password actually works.
        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'a-brand-new-one']);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_the_old_password_stops_working_after_a_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'rafiq@example.com', 'password' => 'the-old-one']);

        $this->post('/forgot-password', ['email' => 'rafiq@example.com']);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'rafiq@example.com',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ]);

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'the-old-one'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_a_forged_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'rafiq@example.com', 'password' => 'the-old-one']);

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'rafiq@example.com',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ])->assertSessionHasErrors('email');

        $this->post('/login', ['login' => 'rafiq@example.com', 'password' => 'the-old-one']);
        $this->assertAuthenticated();
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'rafiq@example.com', 'password' => 'the-old-one']);

        $this->post('/forgot-password', ['email' => 'rafiq@example.com']);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => 'rafiq@example.com',
            'password' => 'a-brand-new-one',
            'password_confirmation' => 'a-brand-new-one',
        ];

        $this->post('/reset-password', $payload)->assertSessionHasNoErrors();

        $this->post('/reset-password', array_merge($payload, [
            'password' => 'a-third-one',
            'password_confirmation' => 'a-third-one',
        ]))->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('a-brand-new-one', $user->fresh()->password));
    }
}
