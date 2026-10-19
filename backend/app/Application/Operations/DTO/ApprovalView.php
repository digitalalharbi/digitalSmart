<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteTarget;
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
        /**
         * The fingerprint the decision was taken against.
         *
         * Null until a decision, and then fixed. It is what makes "has this proposal changed since it was
         * approved" answerable — and carrying out a change nobody approved is the single worst thing the write
         * path could do, so the question is asked from stored state rather than from trust.
         */
        public ?string $decidedFingerprint,
        public ApprovalState $state,
        public EnactmentState $enactment,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $projectId,
        public ?string $projectName,
        /*
         * The ids as well as the names. Both are needed: a name is for a reader, and an id is who a notification
         * goes to. Resolving the requester by name would be a lookup that fails the day two people share one.
         */
        public ?string $requestedById,
        public ?string $requestedByName,
        public DateTimeImmutable $createdAt,
        public ?string $decidedById,
        public ?string $decidedByName,
        public ?DateTimeImmutable $decidedAt,
        public ?string $decisionReason,
        public ?string $withdrawnReason,
        public ?string $enactedByName,
        public ?DateTimeImmutable $enactedAt,
        public ?string $enactmentNote,
        public ?TaskSubjectView $subject,
        public ?string $supersedesId,
        public string $origin = 'person',
        public ?string $agent = null,
        /** @var array<string, mixed>|null */
        public ?array $evidence = null,
        public ?string $risk = null,
        public ?DateTimeImmutable $expiresAt = null,
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

    /**
     * Whether the proposal still describes what was approved.
     *
     * False once somebody has amended it. The write path refuses on this, because executing an amended proposal
     * would be executing a change nobody signed off.
     */
    public function proposalMatchesDecision(): bool
    {
        return $this->decidedFingerprint !== null && $this->decidedFingerprint === $this->fingerprint;
    }

    /**
     * Whether this product could carry this request out on the platform.
     *
     * Distinct from `isEnactedHere()`, which asks whether *approving* it enacts it — that is still false for
     * every kind, and deliberately so: approval authorises, and carrying out is a second act by somebody holding
     * `provider_writes.execute`.
     *
     * This asks whether that second act is available at all, which depends on three things the kind cannot know:
     * whether the proposal names a platform campaign, whether the provider's write is documented, and whether
     * the operation is one this product models.
     */
    public function canBeCarriedOutHere(): bool
    {
        return $this->carryOutBlockedBecause() === null;
    }

    /**
     * Why not, in a sentence an interface can show.
     *
     * Shown rather than hiding the control. A missing button produces a support question; the sentence produces
     * somebody going and making the change in the platform, which is the right outcome.
     */
    public function carryOutBlockedBecause(): ?string
    {
        $operation = WriteOperation::forApproval($this->kind, $this->proposal);

        if ($operation === null) {
            return $this->kind->enactmentBlockedBecause();
        }

        $target = WriteTarget::fromProposal($this->proposal, $operation);

        if ($target === null) {
            return 'This request does not name a platform campaign, so this product cannot carry it out. Most '
                .'approvals do not — make the change in the platform and record it here.';
        }

        $support = $target->provider->writeSupport($operation);

        return $support->allowsAttempt() ? null : sprintf(
            '%s: %s',
            $target->provider->label(),
            $support->because() ?? 'This write is not available.',
        );
    }
}
