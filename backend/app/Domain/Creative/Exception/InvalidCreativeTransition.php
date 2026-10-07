<?php

declare(strict_types=1);

namespace App\Domain\Creative\Exception;

use App\Domain\Creative\CreativeState;
use RuntimeException;

/**
 * A move the board does not allow.
 *
 * Carries the states and the legal alternatives, so an interface can say "briefed work goes into
 * production first" rather than "invalid transition" — the difference between a person fixing their own
 * mistake and a person filing a bug.
 */
final class InvalidCreativeTransition extends RuntimeException
{
    /** @param list<string> $allowed */
    private function __construct(
        string $message,
        public readonly CreativeState $from,
        public readonly ?CreativeState $to,
        public readonly array $allowed,
    ) {
        parent::__construct($message);
    }

    public static function between(CreativeState $from, CreativeState $to): self
    {
        return new self(
            sprintf('Creative work cannot move from %s to %s.', $from->label(), $to->label()),
            $from,
            $to,
            array_map(static fn (CreativeState $state): string => $state->value, $from->allowedNext()),
        );
    }

    /**
     * Sent back without saying what to change.
     *
     * Refused rather than recorded, because the note is the only record of why somebody rejected the
     * work and the next version is a guess without it.
     */
    public static function changesNeedAReason(CreativeState $from): self
    {
        return new self(
            'Say what needs to change. A version sent back without a reason leaves the next one a guess.',
            $from,
            CreativeState::ChangesRequested,
            [CreativeState::ChangesRequested->value],
        );
    }

    /** Approving something nobody has produced. */
    public static function nothingToApprove(CreativeState $from): self
    {
        return new self(
            'There is no version to approve. Approval attaches to a file, not to a request.',
            $from,
            CreativeState::Approved,
            array_map(static fn (CreativeState $state): string => $state->value, $from->allowedNext()),
        );
    }
}
