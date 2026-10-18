<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Agents\Contract\ProposalHistory;
use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\ApprovalState;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentProposalHistory implements ProposalHistory
{
    public function hasPending(string $workspaceId, ApprovalKind $kind, string $externalCampaignId): bool
    {
        return $this->base($workspaceId, $kind, $externalCampaignId)->where('state', ApprovalState::Pending->value)->exists();
    }

    public function hasSince(string $workspaceId, ApprovalKind $kind, string $externalCampaignId, DateTimeImmutable $since): bool
    {
        return $this->base($workspaceId, $kind, $externalCampaignId)
            ->where('state', '!=', ApprovalState::Withdrawn->value)
            ->where('created_at', '>=', $since)
            ->exists();
    }

    private function base(string $workspaceId, ApprovalKind $kind, string $externalCampaignId): Builder
    {
        return DB::table('approval_requests')
            ->where('workspace_id', $workspaceId)
            ->where('kind', $kind->value)
            ->whereRaw("proposal->>'external_campaign_id' = ?", [$externalCampaignId]);
    }
}
