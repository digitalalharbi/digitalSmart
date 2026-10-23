<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\CampaignBinding;
use App\Application\Tracking\Exception\TrackingRefused;

/**
 * Checks a link's campaign hierarchy against the one the product already holds.
 *
 * It owns the rules, not the controller: the ad group must belong to the campaign, the ad to the ad group, the creative to
 * the ad, the account must be bound to the link's project, and nothing from another workspace is ever resolved.
 */
interface CampaignBindingResolver
{
    /**
     * @throws TrackingRefused when the hierarchy does not hold together or reaches outside the project
     */
    public function resolve(
        string $workspaceId,
        string $projectId,
        ?string $platform,
        ?string $externalAccountId,
        ?string $campaignId,
        ?string $adGroupId,
        ?string $adId,
        ?string $creativeId,
    ): ?CampaignBinding;

    /**
     * One level of the picker: accounts the project may read, then campaigns, ad groups or ads beneath a parent.
     *
     * @return list<array{id: string, name: string, detail: ?string}>
     */
    public function options(string $workspaceId, string $projectId, string $level, ?string $parentId, ?string $search): array;
}
