<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What the approval queue is carrying.
 *
 * The figure this object exists for is `outstanding`: **approved changes nobody has carried out**. Every other
 * tool in this space treats an approval as the end of the story, which is why an agency can tell a client a
 * campaign was paused on Tuesday and discover in the invoice that it ran all week. This product does not write
 * to the platforms, so it cannot close that gap by acting — it can only refuse to hide it.
 *
 * `oldestOutstandingDays` is published beside the count for the same reason a lead's age is: three approved
 * changes nobody has made is a backlog, and one of them being eleven days old is a conversation.
 */
final readonly class ApprovalLoad
{
    /**
     * How long a request may wait before the queue calls it out.
     *
     * Three days. Not a measurement — a judgement about an agency's own rhythm, which is why it is a named
     * constant with this sentence next to it rather than a literal in a condition. A request to change live
     * spend that has sat for longer than a long weekend is either not important or not being read.
     */
    public const PATIENCE_DAYS = 3;

    /**
     * @param  array<string, int>  $pendingByAge  counts keyed by `ApprovalAge` value
     * @param  array<string, int>  $outstandingByAge  counts keyed by `ApprovalAge` value
     * @param  array<string, int>  $byKind  pending counts keyed by `ApprovalKind` value
     */
    public function __construct(
        public int $pending,
        /** Pending requests that would move a client's money. */
        public int $pendingTouchingSpend,
        public int $approved,
        public int $declined,
        public int $withdrawn,
        /** Approved, and still waiting on somebody to go and make the change. */
        public int $outstanding,
        public int $confirmed,
        public int $abandoned,
        /** Null when nothing is outstanding, rather than zero. */
        public ?int $oldestOutstandingDays,
        public ?int $oldestPendingDays,
        public array $pendingByAge,
        public array $outstandingByAge,
        public array $byKind,
    ) {}

    /**
     * Of the approved changes that have been settled one way or the other, how many were actually made.
     *
     * The denominator is settled enactments — confirmed plus abandoned — not every approval, because work
     * still waiting is not yet a failure to act. Null when nothing has settled.
     */
    public function carriedOutRate(): ?float
    {
        $settled = $this->confirmed + $this->abandoned;

        return $settled === 0 ? null : round($this->confirmed / $settled * 100, 1);
    }

    /**
     * Of everything decided, how much was approved.
     *
     * Published because an agency with a 100% approval rate does not have an approvals process, it has a
     * formality — and that is worth knowing about your own organisation. Withdrawals are excluded from the
     * denominator: a request the asker took back was never decided.
     *
     * Null when nothing has been decided.
     */
    public function approvalRate(): ?float
    {
        $decided = $this->approved + $this->declined;

        return $decided === 0 ? null : round($this->approved / $decided * 100, 1);
    }

    /** Whether anything needs somebody today: an old pending request, or an approval nobody has acted on. */
    public function needsAttention(): bool
    {
        return $this->outstanding > 0
            || ($this->oldestPendingDays !== null && $this->oldestPendingDays > self::PATIENCE_DAYS);
    }
}
