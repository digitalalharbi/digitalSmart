<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What the intake queue is carrying.
 *
 * The pair worth having is `onTheAgency` beside `onTheClient`. A single "open" figure lets a stalled
 * conversation hide behind a reassuring column: three requests nobody has answered and three where the agency
 * asked a question six weeks ago are both "open", and only one of them is the agency's to fix.
 *
 * `promised` and `unpromised` are the second pair. A queue where most accepted work has no agreed date is one
 * where "we are on it" is the whole of what the client has been told, and that is worth seeing as a number.
 */
final readonly class IntakeLoad
{
    /**
     * How long a client may wait for a first answer before the queue calls it out.
     *
     * Two days. A judgement about what a client reasonably expects, not a measurement, which is why it is a
     * named constant with this sentence beside it. It is deliberately shorter than the approvals queue's three:
     * an internal request waiting over a long weekend is untidy, and a client waiting that long has been
     * ignored.
     */
    public const PATIENCE_DAYS = 2;

    /**
     * @param  array<string, int>  $byState  counts keyed by `ClientRequestState` value
     * @param  array<string, int>  $byKind  open counts keyed by `ClientRequestKind` value
     * @param  array<string, int>  $byUrgency  open counts keyed by `StatedUrgency` value — what clients said
     */
    public function __construct(
        public int $total,
        public int $open,
        /** Open, and waiting on the agency. */
        public int $onTheAgency,
        /** Open, and waiting on the client. Half of why a queue is slow, and the half usually hidden. */
        public int $onTheClient,
        public int $delivered,
        public int $declined,
        /** Open, accepted, and with a date somebody committed to. */
        public int $promised,
        /** Open, accepted, and with no agreed date at all. */
        public int $unpromised,
        /** Open and past the date that was promised. */
        public int $overdue,
        /** Of the open requests, how many the client called urgent. Their word, never the board's priority. */
        public int $calledUrgent,
        public ?int $oldestUnansweredDays,
        public array $byState,
        public array $byKind,
        public array $byUrgency,
    ) {}

    /**
     * Of everything that has closed, how much was delivered rather than declined or withdrawn.
     *
     * The denominator is closed requests. Null when nothing has closed — `0%` and "nothing to measure" look
     * identical on a card and mean opposite things.
     */
    public function deliveryRate(): ?float
    {
        $closed = $this->delivered + $this->declined + $this->withdrawn();

        return $closed === 0 ? null : round($this->delivered / $closed * 100, 1);
    }

    /**
     * What share of accepted work carries a date somebody agreed.
     *
     * Null when nothing is accepted and open. Published because the honest reading of an intake queue's speed
     * depends on it: a queue with no promises cannot be late, and cannot be said to be on time either.
     */
    public function promisedShare(): ?float
    {
        $accepted = $this->promised + $this->unpromised;

        return $accepted === 0 ? null : round($this->promised / $accepted * 100, 1);
    }

    /** Withdrawn requests, derived rather than passed: they are whatever is closed and neither of the others. */
    public function withdrawn(): int
    {
        return max(0, $this->total - $this->open - $this->delivered - $this->declined);
    }

    /** Whether somebody should look at the queue today. */
    public function needsAttention(): bool
    {
        return $this->overdue > 0
            || ($this->oldestUnansweredDays !== null && $this->oldestUnansweredDays > self::PATIENCE_DAYS);
    }
}
