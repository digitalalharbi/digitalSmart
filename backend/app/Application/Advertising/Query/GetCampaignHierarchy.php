<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Advertising\Contract\CampaignHierarchyReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * One campaign's ad groups and ads, for the expandable rows on the Campaigns screen.
 *
 * A campaign the reader cannot see answers not-found, the same as one that does not exist. Ad-group and ad writes
 * are reported as not available: no provider write for them has been read in its own documentation, so the interface
 * shows the control disabled with that reason rather than a button that would fail.
 */
final readonly class GetCampaignHierarchy
{
    public function __construct(
        private CampaignControlReadModel $campaigns,
        private CampaignHierarchyReadModel $hierarchy,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array{groups: list<array<string, mixed>>, writes: array<string, string>} */
    public function __invoke(string $campaignId): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $this->campaigns->writeTarget($workspaceId, $this->visibility->visibleProjectIds(), $campaignId)
            ?? throw ResourceNotFound::of('Campaign');

        $to = $this->clock->now()->modify('-1 day');
        $split = $to->modify('-6 days');
        $from = $to->modify('-27 days');

        $ads = [];

        foreach ($this->hierarchy->ads($workspaceId, [$campaignId], $from, $split, $to) as $ad) {
            $ads[(string) $ad['ad_group_id']][] = [...$ad, ...AdSignals::of($ad)];
        }

        $groups = array_map(
            static fn (array $g): array => [...$g, 'ads' => $ads[(string) $g['id']] ?? []],
            $this->hierarchy->groups($workspaceId, $campaignId, $from, $to),
        );

        return [
            'groups' => $groups,
            'writes' => ['ad_group' => 'not_documented', 'ad' => 'not_documented'],
        ];
    }
}
