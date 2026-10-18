<?php

declare(strict_types=1);

namespace App\Application\Agents\Contract;

use App\Domain\Operations\ApprovalKind;
use DateTimeImmutable;

/** What has already been proposed for a platform campaign — the memory behind the duplicate and cooldown limits. */
interface ProposalHistory
{
    /** Whether a request of this kind for this platform campaign is waiting for a decision. */
    public function hasPending(string $workspaceId, ApprovalKind $kind, string $externalCampaignId): bool;

    /** Whether one of this kind was raised since then, in any state but withdrawn. */
    public function hasSince(string $workspaceId, ApprovalKind $kind, string $externalCampaignId, DateTimeImmutable $since): bool;
}
