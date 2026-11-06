<?php

declare(strict_types=1);

namespace App\Application\Influencers\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Influencers\Contract\InfluencerRepository;
use App\Application\Influencers\DTO\InfluencerView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingEventStore;
use App\Domain\Access\Permission;

/**
 * The influencers a reader may see, each with how many links were made for them and what valid traffic those links brought.
 *
 * Counts are the tracking module's own — valid traffic only, test and automated visits excluded — summed over the influencer's
 * links. Nothing here is money: no fee is recorded, so no cost per anything is shown.
 */
final readonly class ListInfluencers
{
    public function __construct(
        private InfluencerRepository $influencers,
        private TrackingEventStore $events,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<InfluencerView> */
    public function __invoke(?string $clientId = null, bool $includeArchived = false): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;
        $rows = $this->influencers->list($workspaceId, $this->visibility->visibleProjectIds(), $clientId, $includeArchived);

        return $this->withTotals($workspaceId, $rows);
    }

    public function one(string $id): InfluencerView
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;
        $row = $this->influencers->find($workspaceId, $this->visibility->visibleProjectIds(), $id) ?? throw ResourceNotFound::of('Influencer');

        return $this->withTotals($workspaceId, [$row])[0] ?? $row;
    }

    /**
     * @param  list<InfluencerView>  $rows
     * @return list<InfluencerView>
     */
    private function withTotals(string $workspaceId, array $rows): array
    {
        $linkIds = $this->influencers->linkIds($workspaceId, array_map(static fn (InfluencerView $i): string => $i->id, $rows));
        $counts = $this->events->countsFor(array_merge([], ...array_values($linkIds)));

        return array_map(static function (InfluencerView $i) use ($linkIds, $counts): InfluencerView {
            $clicks = 0;
            $visitors = 0;

            foreach ($linkIds[$i->id] ?? [] as $linkId) {
                $clicks += $counts[$linkId]->clicks ?? 0;
                $visitors += $counts[$linkId]->visitors ?? 0;
            }

            return $i->withTotals(count($linkIds[$i->id] ?? []), $clicks, $visitors);
        }, $rows);
    }
}
