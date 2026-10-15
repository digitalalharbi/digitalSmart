<?php

declare(strict_types=1);

namespace App\Application\Commerce\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Commerce\DTO\StoreRollupRow;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * Every store the reader may see, one row each — the agency-wide commerce view.
 *
 * One statement however many stores there are; the revenue source is still required, for the same reason it
 * is on the single-client view.
 */
final readonly class GetStoreRollup
{
    public const int LIMIT = 200;

    public function __construct(
        private StoreAnalyticsReadModel $analytics,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<StoreRollupRow> */
    public function __invoke(RevenueSource $revenueSource, DateTimeImmutable $from, DateTimeImmutable $until, ?string $clientId = null): array
    {
        $this->guard->ensure(GetStoreAnalytics::PERMISSION);

        return $this->analytics->byStore(new StoreQuery(
            workspaceId: $this->actor->workspaceId()->value,
            revenueSource: $revenueSource,
            from: $from,
            until: $until,
            visibleProjectIds: $this->visibility->visibleProjectIds(),
            clientId: $clientId,
            limit: self::LIMIT,
        ));
    }
}
