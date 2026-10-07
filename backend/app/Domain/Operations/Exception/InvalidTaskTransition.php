<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use App\Domain\Operations\TaskState;
use RuntimeException;

/**
 * A move the board does not allow.
 *
 * Carries the legal alternatives, so an interface can say "blocked work goes back into progress first"
 * rather than "invalid transition" — the difference between somebody fixing their own mistake and
 * somebody filing a bug.
 */
final class InvalidTaskTransition extends RuntimeException
{
    /** @param list<string> $allowed */
    private function __construct(
        string $message,
        public readonly TaskState $from,
        public readonly ?TaskState $to,
        public readonly array $allowed,
    ) {
        parent::__construct($message);
    }

    public static function between(TaskState $from, TaskState $to): self
    {
        return new self(
            $from === TaskState::Blocked && $to === TaskState::Done
                /*
                 * Named rather than generic, because this is the move people actually try: the block
                 * cleared, the work got done, and the card jumped. Recording it loses both the fact that
                 * somebody did the work and any way to measure what the block cost.
                 */
                ? 'Blocked work cannot go straight to done. Put it back in progress first, so the board '
                    .'records that somebody unblocked it and did the work.'
                : sprintf('A task cannot move from %s to %s.', $from->label(), $to->label()),
            $from,
            $to,
            array_map(static fn (TaskState $state): string => $state->value, $from->allowedNext()),
        );
    }

    /**
     * Blocked or cancelled without saying why.
     *
     * Refused rather than recorded. For blocked, the reason is the only thing that tells the next person
     * who to chase; for cancelled, it is the only record of a decision not to do the work.
     */
    public static function reasonRequired(TaskState $from, TaskState $to): self
    {
        return new self(
            $to === TaskState::Blocked
                ? 'Say what is blocking it. A blocked card without a reason is one nobody can clear.'
                : 'Say why it was cancelled. Deciding not to do the work is a decision somebody will ask about.',
            $from,
            $to,
            [$to->value],
        );
    }
}
