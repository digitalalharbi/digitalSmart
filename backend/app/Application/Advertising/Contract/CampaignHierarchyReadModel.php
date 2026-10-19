<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use DateTimeImmutable;

/**
 * What sits under a campaign: its ad groups (ad sets), their ads, and each ad's creative, with figures.
 *
 * Figures are sums over the window, kept null where nothing was reported. The window is split at `$split`, so an
 * ad's last week can be compared with the week before — the measurement a fatigue signal rests on.
 */
interface CampaignHierarchyReadModel
{
    /** @return list<array<string, mixed>> */
    public function groups(string $workspaceId, string $campaignId, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * @param  list<string>  $campaignIds
     * @return list<array<string, mixed>>
     */
    public function ads(string $workspaceId, array $campaignIds, DateTimeImmutable $from, DateTimeImmutable $split, DateTimeImmutable $to): array;
}
