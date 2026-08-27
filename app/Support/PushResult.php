<?php

namespace App\Support;

/**
 * What one push run did.
 *
 * `pruned` is counted separately from `failed` because the two mean opposite
 * things operationally: a pruned subscription is the system working — a
 * browser that was uninstalled or had its permission revoked, which the push
 * service reports as 404 or 410 and which we are *required* to stop sending
 * to. A failure is a message that should have arrived and did not.
 */
readonly class PushResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public int $sent = 0,
        public int $failed = 0,
        public int $pruned = 0,
        public array $reasons = [],
    ) {}

    public function total(): int
    {
        return $this->sent + $this->failed + $this->pruned;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->sent + $other->sent,
            $this->failed + $other->failed,
            $this->pruned + $other->pruned,
            // Distinct reasons only: a thousand subscriptions behind one dead
            // push service produce one problem, not a thousand log lines.
            array_values(array_unique([...$this->reasons, ...$other->reasons])),
        );
    }
}
