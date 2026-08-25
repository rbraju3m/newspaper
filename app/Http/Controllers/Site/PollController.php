<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PollController extends Controller
{
    public function vote(Request $request, Poll $poll): JsonResponse
    {
        if ($poll->isClosed()) {
            return response()->json(['message' => 'এই জরিপটি বন্ধ হয়ে গেছে।'], 422);
        }

        $validated = $request->validate([
            'option_id' => ['required', 'integer', 'exists:poll_options,id'],
        ]);

        // Guests are identified by a salted hash of IP+UA. Not bulletproof, but
        // it stops casual double-voting without storing a raw IP as an identity.
        $fingerprint = $request->user()
            ? null
            : hash('sha256', $request->ip().'|'.$request->userAgent().'|'.config('app.key'));

        if ($poll->hasVoted($request->user(), $fingerprint)) {
            return response()->json([
                'message' => 'আপনি ইতিমধ্যে ভোট দিয়েছেন।',
                'results' => $this->results($poll),
            ], 409);
        }

        DB::transaction(function () use ($poll, $validated, $request, $fingerprint) {
            PollVote::create([
                'poll_id' => $poll->id,
                'poll_option_id' => $validated['option_id'],
                'user_id' => $request->user()?->id,
                'fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);

            $poll->options()->whereKey($validated['option_id'])->increment('votes_count');
            $poll->increment('votes_count');
        });

        return response()->json(['results' => $this->results($poll->fresh('options'))]);
    }

    private function results(Poll $poll): array
    {
        return [
            'total' => $poll->votes_count,
            'options' => $poll->options->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->label,
                'votes' => $o->votes_count,
                'percentage' => $o->percentage($poll->votes_count),
            ])->all(),
        ];
    }
}
