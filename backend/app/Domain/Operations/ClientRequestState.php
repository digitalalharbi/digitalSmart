<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Operations\Exception\InvalidRequestTransition;

/**
 * Where something a client asked for has got to.
 *
 * The state worth arguing about is `NeedsInformation`, and it is here because **the commonest reason a client
 * request stalls is that the agency is waiting on the client** — and a queue that cannot tell "we are working on
 * it" from "we asked you something three weeks ago" lets that hide behind a reassuring column. Separating them
 * is the difference between a slow agency and a stalled conversation, and only one of those is the agency's
 * fault to fix.
 *
 * `Declined` carries a reason, and that reason is **shown to the client**. A request that disappeared from an
 * intake queue with the client never told why is the single most common failure of an agency's intake.
 */
enum ClientRequestState: string
{
    /** Arrived. Nobody has looked at it yet. */
    case Submitted = 'submitted';

    /** Waiting on the client to answer something. The state that stops a stall hiding. */
    case NeedsInformation = 'needs_information';

    /** Agreed to. Work may or may not have started. */
    case Accepted = 'accepted';

    case InProgress = 'in_progress';

    case Delivered = 'delivered';

    /** Said no, with a reason the client sees. */
    case Declined = 'declined';

    /** The client took it back. */
    case Withdrawn = 'withdrawn';

    /**
     * The states this one may move to.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Submitted => [self::NeedsInformation, self::Accepted, self::Declined, self::Withdrawn],
            // Back to submitted once the client answers: it returns to the queue rather than skipping triage.
            self::NeedsInformation => [self::Submitted, self::Accepted, self::Declined, self::Withdrawn],
            self::Accepted => [self::InProgress, self::NeedsInformation, self::Declined, self::Withdrawn],
            self::InProgress => [self::Delivered, self::NeedsInformation, self::Withdrawn],
            /*
             * Delivered work can be reopened. A client saying "this is not what I asked for" is the most
             * important message in this module, and a terminal state would make the honest response a second
             * request that loses the history of the first.
             */
            self::Delivered => [self::InProgress],
            self::Declined => [self::Submitted],
            self::Withdrawn => [self::Submitted],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @throws InvalidRequestTransition */
    public function assertCanMoveTo(self $next): void
    {
        if (! $this->canMoveTo($next)) {
            throw InvalidRequestTransition::between($this, $next);
        }
    }

    /**
     * Whether moving here needs a reason.
     *
     * Declining does, and the reason reaches the client — see the note above. Asking for information does too:
     * "we need something from you" with no statement of what is a message that cannot be answered, and it is
     * the state most likely to sit for weeks.
     */
    public function needsReason(): bool
    {
        return $this === self::Declined || $this === self::NeedsInformation;
    }

    /** Still a live conversation. */
    public function isOpen(): bool
    {
        return $this !== self::Delivered && $this !== self::Declined && $this !== self::Withdrawn;
    }

    /**
     * Whether the agency is the one holding this up.
     *
     * False for `NeedsInformation`: the ball is with the client, and counting that against the agency's own
     * response time would make the figure describe the client rather than the agency.
     */
    public function isOnTheAgency(): bool
    {
        return $this === self::Submitted || $this === self::Accepted || $this === self::InProgress;
    }

    /** Whether the client is the one being waited on. Published, because it is half of why a queue is slow. */
    public function isOnTheClient(): bool
    {
        return $this === self::NeedsInformation;
    }

    public function boardPosition(): int
    {
        return match ($this) {
            self::Submitted => 0,
            self::NeedsInformation => 1,
            self::Accepted => 2,
            self::InProgress => 3,
            self::Delivered => 4,
            self::Declined => 5,
            self::Withdrawn => 6,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'New',
            self::NeedsInformation => 'Waiting on the client',
            self::Accepted => 'Accepted',
            self::InProgress => 'In progress',
            self::Delivered => 'Delivered',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<self> */
    public static function board(): array
    {
        $states = self::cases();

        usort($states, static fn (self $a, self $b): int => $a->boardPosition() <=> $b->boardPosition());

        return $states;
    }
}
