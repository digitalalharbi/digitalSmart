<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

use App\Application\Advertising\Contract\CampaignHierarchyReadModel;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\EntityStatus;

/**
 * Every ad under the reader's live campaigns, with its click-through this week against last and its frequency.
 *
 * The campaigns come from the same query the Campaigns screen runs, so visibility and permission are the screen's
 * own; the ads are read for those campaigns only. Figures, not verdicts: what counts as tiring is the reader's call.
 */
final readonly class ListAdSignals
{
    public function __construct(
        private GetCampaignControl $campaigns,
        private CampaignHierarchyReadModel $hierarchy,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    /** @return array{ads: list<array<string, mixed>>, campaigns: int, matched: int} */
    public function __invoke(?string $projectId, int $limit, int $offset, int $minImpressions): array
    {
        $page = ($this->campaigns)(
            new CampaignControlFilter(projectIds: $projectId === null ? [] : [$projectId], statuses: [EntityStatus::Active]),
            $limit,
            $offset,
        );

        $live = array_values(array_filter($page->rows, static fn (CampaignControlRow $r): bool => $r->status === EntityStatus::Active));
        $byId = [];

        foreach ($live as $row) {
            $byId[$row->campaignId] = $row;
        }

        $to = $this->clock->now()->modify('-1 day');
        $split = $to->modify('-6 days');
        $from = $to->modify('-27 days');
        $ads = [];

        foreach ($this->hierarchy->ads($this->actor->workspaceId()->value, array_keys($byId), $from, $split, $to) as $ad) {
            if ((int) ($ad['impressions_now'] ?? 0) < $minImpressions || ($ad['status'] ?? '') !== 'active') {
                continue;
            }

            $campaign = $byId[(string) $ad['campaign_id']];
            $ads[] = [
                ...$ad,
                ...AdSignals::of($ad),
                'campaign_name' => $campaign->campaignName,
                'client_id' => $campaign->clientId,
                'project_id' => $campaign->projectId,
            ];
        }

        return ['ads' => $ads, 'campaigns' => count($byId), 'matched' => $page->matched];
    }
}
