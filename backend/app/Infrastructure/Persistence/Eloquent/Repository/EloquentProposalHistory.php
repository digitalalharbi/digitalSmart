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
    public function hasPending(string $workspaceId, ApprovalKind $kind, string $externalCampaignId, string $level = 'campaign'): bool
    {
        return $this->base($workspaceId, $kind, $externalCampaignId, $level)->where('state', ApprovalState::Pending->value)->exists();
    }

    public function hasSince(string $workspaceId, ApprovalKind $kind, string $externalCampaignId, DateTimeImmutable $since, string $level = 'campaign'): bool
    {
        return $this->base($workspaceId, $kind, $externalCampaignId, $level)
            ->where('state', '!=', ApprovalState::Withdrawn->value)
            ->where('created_at', '>=', $since)
            ->exists();
    }

    public function hasOpenTask(string $workspaceId, string $title): bool
    {
        return DB::table('tasks')
            ->where('workspace_id', $workspaceId)
            ->where('title', $title)
            ->whereNotIn('state', ['done', 'cancelled'])
            ->exists();
    }

    /**
     * Proposals about one entity at one level. A campaign's and an ad group's proposals carry the same campaign id,
     * so the level is part of the key: pausing an ad set does not block a budget change on its campaign.
     */
    private function base(string $workspaceId, ApprovalKind $kind, string $externalId, string $level): Builder
    {
        $field = match ($level) {
            'ad_group' => 'external_ad_group_id',
            'ad' => 'external_ad_id',
            default => 'external_campaign_id',
        };

        return DB::table('approval_requests')
            ->where('workspace_id', $workspaceId)
            ->where('kind', $kind->value)
            ->whereRaw("coalesce(proposal->>'level', 'campaign') = ?", [$level])
            ->whereRaw(sprintf("proposal->>'%s' = ?", $field), [$externalId]);
    }
}
