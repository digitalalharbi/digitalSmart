<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Advertising;

use App\Application\Advertising\DTO\CampaignOwnershipView;
use App\Domain\Advertising\CampaignOwnership;

final readonly class CampaignOwnershipResource
{
    /** @return array<string, mixed> */
    public static function one(CampaignOwnershipView $campaign): array
    {
        return [
            'id' => $campaign->campaignId,
            'name' => $campaign->campaignName,
            'provider' => $campaign->provider,
            'status' => $campaign->status,
            'external_account' => [
                'id' => $campaign->externalAccountId,
                /* Rule 92: the human label leads, the native id is secondary and lives on the account page. */
                'name' => $campaign->externalAccountName,
            ],
            'ownership' => $campaign->ownership->value,
            'ownership_label' => $campaign->ownership->label(),
            'needs_attention' => $campaign->ownership->needsAttention(),
            'project_id' => $campaign->projectId,
            'project_name' => $campaign->projectName,
            'client_id' => $campaign->clientId,
            'client_name' => $campaign->clientName,
            /* What this campaign may legally be assigned to. The server decides it; the picker reads it. */
            'candidate_projects' => $campaign->candidateProjects,
        ];
    }

    /**
     * The vocabulary, published rather than hard-coded in the client.
     *
     * @return array<string, mixed>
     */
    public static function vocabulary(): array
    {
        return [
            'ownership' => array_map(static fn (CampaignOwnership $state): array => [
                'ownership' => $state->value,
                'label' => $state->label(),
                'needs_attention' => $state->needsAttention(),
            ], CampaignOwnership::cases()),
        ];
    }
}
