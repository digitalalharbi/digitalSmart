<?php

declare(strict_types=1);

namespace App\Application\Analytics\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\CreativeIntelligence;
use App\Application\Analytics\Service\CreativeIntelligenceReader;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * How a workspace's creatives performed — each asset, or each ad that runs one — over a period.
 *
 * **One engine.** The figures come from the analytics read model, grouped by creative or by ad, so a creative's spend here
 * is the spend the Analytics page would show for the same selection; the trend is that layer's series; the verdict is a
 * domain rule over those figures. Nothing here computes a metric.
 *
 * **Asset and ad are different questions.** `asset` groups by the platform's creative, which several ads can run: "which
 * piece of work performs best". `ad` groups by the ad: "which placement of it". An ad with no known creative is in the
 * second and in none of the first, never under another asset — and nothing is merged by file name or caption.
 *
 * **Bounded.** The cohort is the largest five hundred creatives by spend in the selection, one read for the period and one
 * for the period before, each a single grouped query; a page is a slice of it; the catalogue and the trends are one query
 * each for the page. No call to any platform is made while this runs.
 *
 * Scope is the caller's: workspace, then the projects they can see, then whatever they narrowed to.
 */
final readonly class GetCreativeIntelligence
{
    public function __construct(
        private CreativeIntelligenceReader $reader,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(
        DateRange $range,
        AnalyticsFilters $filters,
        string $grain = 'asset',
        ?string $format = null,
        ?string $verdict = null,
        int $limit = 24,
        int $offset = 0,
    ): CreativeIntelligence {
        $this->guard->ensure(Permission::AnalyticsView);

        $grain = $grain === 'ad' ? 'ad' : 'asset';
        $scope = $this->scope($filters);

        if ($scope === null) {
            return new CreativeIntelligence($grain, $range, $range->previousPeriod(), [], 0, false, [], []);
        }

        return $this->reader->read($this->actor->workspaceId()->value, $range, $scope, $grain, $format, $verdict, $limit, $offset);
    }

    private function scope(AnalyticsFilters $filters): ?AnalyticsFilters
    {
        $visible = $this->visibility->visibleProjectIds();

        if ($visible === null) {
            return $filters;
        }

        $allowed = $filters->projectIds === []
            ? $visible
            : array_values(array_intersect($filters->projectIds, $visible));

        if ($allowed === []) {
            return null;
        }

        return new AnalyticsFilters(
            providers: $filters->providers,
            externalAccountIds: $filters->externalAccountIds,
            campaignIds: $filters->campaignIds,
            adGroupIds: $filters->adGroupIds,
            adIds: $filters->adIds,
            projectIds: $allowed,
            clientId: $filters->clientId,
        );
    }
}
