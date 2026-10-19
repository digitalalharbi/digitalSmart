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
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\WriteOperation;

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

    /** @return array{groups: list<array<string, mixed>>, creatives: list<array<string, mixed>>, writes: array<string, array<string, array{support: 'documented'|'not_offered', because: string|null}>>} */
    public function __invoke(string $campaignId): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $target = $this->campaigns->writeTarget($workspaceId, $this->visibility->visibleProjectIds(), $campaignId)
            ?? throw ResourceNotFound::of('Campaign');
        $provider = Provider::tryFrom($target['provider']);

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

        /*
         * The creatives these ads run, once each — the platform's own creative, linked from every ad that uses it, never
         * copied. A creative used by several ads is one row with its ads counted.
         */
        $creatives = [];

        foreach ($ads as $groupAds) {
            foreach ($groupAds as $ad) {
                $creative = $ad['creative'];

                if ($creative === null) {
                    continue;
                }

                $creatives[$creative['id']] ??= [...$creative, 'ads' => 0, 'ad_names' => []];
                $creatives[$creative['id']]['ads']++;
                $creatives[$creative['id']]['ad_names'][] = $ad['name'];
            }
        }

        return [
            'groups' => $groups,
            'creatives' => array_values($creatives),
            'writes' => [
                'ad_group' => $this->writes($provider, AdEntityType::AdGroup),
                'ad' => $this->writes($provider, AdEntityType::Ad),
            ],
        ];
    }

    /**
     * What a person may ask for at a level, on this campaign's own platform: documented changes only, each with the
     * platform's reason where it is not — never inferred from what the platform lets a campaign do.
     *
     * @return array<string, array{support: 'documented'|'not_offered', because: string|null}>
     */
    private function writes(?Provider $provider, AdEntityType $level): array
    {
        $out = [];

        foreach (['pause', 'resume', 'budget'] as $change) {
            $operation = WriteOperation::at($level, $change);

            if ($operation === null) {
                continue;
            }

            $documented = $provider !== null && $provider->writeSupport($operation)->allowsAttempt();
            $out[$change] = [
                'support' => $documented ? 'documented' : 'not_offered',
                'because' => $documented || $provider === null ? null : ProviderEntityWrites::notOffered($provider, $operation),
            ];
        }

        return $out;
    }
}
