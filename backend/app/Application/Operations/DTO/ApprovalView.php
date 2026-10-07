<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\ApprovalAge;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use DateTimeImmutable;

/** One approval request, with everything a decider needs in front of them. */
final readonly class ApprovalView
{
    /** @param array<string, mixed> $proposal */
    public function __construct(
        public string $id,
        public ApprovalKind $kind,
        public string $title,
        public string $rationale,
        public array $proposal,
        /** The hash a decision must name. See `ProposalFingerprint`. */
        public string $fingerprint,
        public ApprovalState $state,
        public EnactmentState $enactment,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $requestedByName,
        public DateTimeImmutable $createdAt,
        public ?string $decidedByName,
        public ?DateTimeImmutable $decidedAt,
        public ?string $decisionReason,
        public ?string $withdrawnReason,
        public ?string $enactedByName,
        public ?DateTimeImmutable $enactedAt,
        public ?string $enactmentNote,
        public ?TaskSubjectView $subject,
        public ?string $supersedesId,
    ) {}

    /**
     * How long this has been waiting, measured from whichever clock matters.
     *
     * While pending, from when it was asked. Once approved and awaiting a hand, **from when it was approved** —
     * because the question then is not how long the decision took but how long the change has gone unmade, and
     * measuring from the request would flatter a slow decision followed by prompt action.
     */
    public function waitingSince(): ?DateTimeImmutable
    {
        if ($this->state->isPending()) {
            return $this->createdAt;
        }

        return $this->enactment->isOutstanding() ? $this->decidedAt : null;
    }

    public function age(DateTimeImmutable $now): ?ApprovalAge
    {
        $since = $this->waitingSince();

        return $since === null ? null : ApprovalAge::of($since, $now);
    }

    /** Whole days waiting, or null when nothing is waiting. */
    public function waitingDays(DateTimeImmutable $now): ?int
    {
        $since = $this->waitingSince();

        return $since === null ? null : (int) $since->setTime(0, 0)->diff($now->setTime(0, 0))->days;
    }

    /**
     * How long the decision itself took, in whole days. Null while undecided.
     *
     * Separate from the waiting age on purpose: an agency improving its approval turnaround and an agency
     * improving how fast it acts on approvals are two different problems with two different owners.
     */
    public function decisionDays(): ?int
    {
        return $this->decidedAt === null
            ? null
            : (int) $this->createdAt->setTime(0, 0)->diff($this->decidedAt->setTime(0, 0))->days;
    }

    /**
     * Whether this product would carry the change out itself.
     *
     * Always false today, and shown on the request rather than inferred, so nobody approves a pause believing
     * anything was paused. See `ApprovalKind`.
     */
    public function isEnactedHere(): bool
    {
        return $this->kind->isEnactedByThisProduct();
    }
}
