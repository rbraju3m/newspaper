<?php

namespace Tests\Feature;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Poll voting, including the guest fingerprint.
 *
 * Guests are identified by a salted hash of IP + user agent. It is not
 * bulletproof and is not meant to be — the test that matters is that it stops
 * casual double-voting while never storing a raw IP as an identity.
 */
class PollVotingTest extends TestCase
{
    use RefreshDatabase;

    private Poll $poll;

    private PollOption $first;

    private PollOption $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poll = Poll::create([
            'question' => 'আপনি কোনটি পছন্দ করেন?',
            'slug' => 'test-poll',
            'is_active' => true,
        ]);

        $this->first = PollOption::create(['poll_id' => $this->poll->id, 'label' => 'প্রথম', 'position' => 1]);
        $this->second = PollOption::create(['poll_id' => $this->poll->id, 'label' => 'দ্বিতীয়', 'position' => 2]);
    }

    public function test_a_guest_can_vote_and_gets_results_back(): void
    {
        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id])
            ->assertOk()
            ->assertJsonPath('results.total', 1)
            ->assertJsonPath('results.options.0.votes', 1)
            ->assertJsonPath('results.options.0.percentage', 100);

        $this->assertSame(1, $this->poll->fresh()->votes_count);
        $this->assertSame(1, $this->first->fresh()->votes_count);
    }

    public function test_a_guests_raw_ip_is_never_stored_as_the_identity(): void
    {
        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id]);

        $fingerprint = PollVote::firstOrFail()->fingerprint;

        $this->assertNotNull($fingerprint);
        $this->assertSame(64, strlen($fingerprint), 'Expected a sha256 hex digest.');
        $this->assertStringNotContainsString('127.0.0.1', $fingerprint);
    }

    public function test_the_same_guest_cannot_vote_twice(): void
    {
        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id])->assertOk();

        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->second->id])
            ->assertStatus(409)
            // Refused, but the results still come back so the page can show them.
            ->assertJsonPath('results.total', 1);

        $this->assertSame(1, PollVote::count());
        $this->assertSame(0, $this->second->fresh()->votes_count);
    }

    public function test_a_signed_in_reader_is_tracked_by_account_not_fingerprint(): void
    {
        $user = User::factory()->create()->fresh();

        $this->actingAs($user)
            ->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id])
            ->assertOk();

        $vote = PollVote::firstOrFail();

        $this->assertSame($user->id, $vote->user_id);
        $this->assertNull($vote->fingerprint);

        $this->actingAs($user)
            ->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->second->id])
            ->assertStatus(409);

        $this->assertSame(1, PollVote::count());
    }

    public function test_two_different_readers_can_both_vote(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id])
            ->assertOk();

        $this->actingAs(User::factory()->create()->fresh())
            ->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->second->id])
            ->assertOk();

        $this->assertSame(2, $this->poll->fresh()->votes_count);
        $this->assertSame(1, $this->first->fresh()->votes_count);
        $this->assertSame(1, $this->second->fresh()->votes_count);
    }

    public function test_an_inactive_poll_refuses_votes(): void
    {
        $this->poll->update(['is_active' => false]);

        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id])
            ->assertStatus(422);

        $this->assertSame(0, PollVote::count());
    }

    public function test_a_closed_poll_refuses_votes(): void
    {
        $this->poll->update(['closes_at' => now()->subHour()]);

        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id])
            ->assertStatus(422);

        $this->assertSame(0, PollVote::count());
    }

    public function test_an_option_belonging_to_another_poll_is_rejected(): void
    {
        // Same shape as the comment parent_id graft: without scoping the
        // exists rule to this poll, the vote is written, this poll's total is
        // incremented, and no option's own count moves — so the total stops
        // equalling the sum of its options and every percentage is wrong.
        $other = Poll::create(['question' => 'অন্য জরিপ', 'slug' => 'other-poll', 'is_active' => true]);
        $foreign = PollOption::create(['poll_id' => $other->id, 'label' => 'বাইরের', 'position' => 1]);

        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $foreign->id])
            ->assertStatus(422);

        $this->assertSame(0, PollVote::count());
        $this->assertSame(0, $this->poll->fresh()->votes_count);
    }

    public function test_the_total_always_equals_the_sum_of_its_options(): void
    {
        $this->actingAs(User::factory()->create()->fresh())
            ->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->first->id]);
        $this->actingAs(User::factory()->create()->fresh())
            ->postJson("/polls/{$this->poll->id}/vote", ['option_id' => $this->second->id]);

        $poll = $this->poll->fresh();

        $this->assertSame(
            $poll->votes_count,
            $poll->options->sum('votes_count'),
            'The results bar computes percentages against the poll total.'
        );
    }

    public function test_an_unknown_option_is_rejected(): void
    {
        $this->postJson("/polls/{$this->poll->id}/vote", ['option_id' => 99999])
            ->assertStatus(422);

        $this->assertSame(0, PollVote::count());
    }
}
