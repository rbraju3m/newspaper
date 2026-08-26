<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, ?string $hashFor = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($hashFor ?? $user->email),
        ]);
    }

    public function test_the_notice_renders_for_an_unverified_reader(): void
    {
        $user = User::factory()->unverified()->create()->fresh();

        $this->actingAs($user)->get('/verify-email')->assertOk();
    }

    public function test_an_already_verified_reader_is_sent_to_their_account(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->get('/verify-email')
            ->assertRedirect(route('account.index'));
    }

    public function test_a_valid_signed_link_verifies_the_email(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create()->fresh();

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect(route('account.index'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_a_link_whose_hash_does_not_match_the_email_is_refused(): void
    {
        // Guards against a link being reused after the reader changes address.
        $user = User::factory()->unverified()->create()->fresh();

        $this->actingAs($user)
            ->get($this->verificationUrl($user, 'someone-else@example.com'))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create()->fresh();

        $this->actingAs($user)
            ->get("/verify-email/{$user->id}/".sha1($user->email))
            ->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create()->fresh();

        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_reader_can_ask_for_another_link(): void
    {
        $user = User::factory()->unverified()->create()->fresh();

        $this->actingAs($user)
            ->from('/verify-email')
            ->post('/verify-email/resend')
            ->assertRedirect('/verify-email');
    }

    public function test_guests_cannot_reach_the_verification_screens(): void
    {
        $this->get('/verify-email')->assertRedirect('/login');
    }
}
