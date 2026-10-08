<?php

declare(strict_types=1);

namespace App\Domain\Leads;

use App\Domain\Commerce\AttributionEvidence;
use DateTimeImmutable;

/**
 * One of the two leads in a merge, reduced to the facts the decision turns on.
 *
 * A plain value object rather than a lead: the direction of a merge is decided by four things, and passing a
 * whole record in would invite a fifth to creep into the rule where nobody would find it again.
 */
final readonly class LeadMergeCandidate
{
    public function __construct(
        public string $id,
        public LeadSourceKind $sourceKind,
        public LeadStage $stage,
        public AttributionEvidence $evidence,
        public DateTimeImmutable $receivedAt,
        public bool $isAlreadyMerged = false,
    ) {}
}
