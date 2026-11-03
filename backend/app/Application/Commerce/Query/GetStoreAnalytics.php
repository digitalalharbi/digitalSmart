<?php

declare(strict_types=1);

namespace App\Application\Commerce\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Commerce\Contract\StoreAnalyticsReadModel;
use App\Application\Commerce\DTO\StoreAnalyticsView;
use App\Application\Commerce\DTO\StoreQuery;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Commerce\TrendGrain;
use DateTimeImmutable;

/**
 * What a client's stores sold, according to one named authority.
 *
 * The revenue source is a **required argument with no default**, and that is the single most important
 * thing about this use case. There is no "all sources" option and there never will be: a Salla total and
 * a GA4 total measure different things — one counts invoices, the other counts what a browser reported
 * before the payment failed — and an interface that let somebody ask for both at once would be offering a
 * number nobody could defend.
 *
 * Everything else here is assembling a view. The judgement is in the read model's predicates and in the
 * domain's own definitions of what counts as money.
 */
final readonly class GetStoreAnalytics
{
    /**
     * The permission this read needs, published so the Interface layer can apply it **first**.
     *
     * Commerce figures are a client's money, so they are gated on the permission the rest of the reporting
     * surface already uses rather than on a new one: a role that may not read analytics has no business reading
     * revenue.
     *
     * It is a constant rather than a second copy of the name because the endpoint has to refuse an unauthorised
     * caller *before* it tells them a revenue source is required — a 422 naming the parameter is a statement that
     * the endpoint exists and would answer.
     */
    public const Permission PERMISSION = Permission::AnalyticsView;

    public function __construct(
        private StoreAnalyticsReadModel $analytics,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(
        RevenueSource $revenueSource,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?string $clientId = null,
        ?string $storeId = null,
        int $limit = 20,
        TrendGrain $grain = TrendGrain::Day,
        bool $comparePrevious = false,
    ): StoreAnalyticsView {
        // Again, so the use case is safe however it is called. See `PERMISSION`.
        $this->guard->ensure(self::PERMISSION);

        $query = new StoreQuery(
            workspaceId: $this->actor->workspaceId()->value,
            revenueSource: $revenueSource,
            from: $from,
            until: $until,
            visibleProjectIds: $this->visibility->visibleProjectIds(),
            clientId: $clientId,
            storeId: $storeId,
            // Clamped rather than trusted: a caller asking for ten thousand rows gets a hundred.
            limit: max(1, min($limit, 100)),
            grain: $grain,
        );

        return new StoreAnalyticsView(
            summary: $this->analytics->summary($query),
            byChannel: $this->analytics->byChannel($query),
            byCampaign: $this->analytics->byCampaign($query),
            byProduct: $this->analytics->byProduct($query),
            daily: $this->analytics->daily($query),
            customerMix: $this->analytics->customerMix($query),
            from: $from,
            until: $until,
            ledger: $this->analytics->ledger($query),
            series: $this->analytics->series($query),
            comparison: $comparePrevious ? $this->analytics->comparison($query) : null,
        );
    }
}
