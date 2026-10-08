<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Service;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Application\Analytics\Query\GetAnalytics;
use App\Application\Intelligence\DTO\AnalysisContext;
use App\Application\Measurement\Service\TrackingHealthComposer;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * Fetches everything the analysers read, once.
 *
 * It composes `GetAnalytics` rather than the read model directly, which is the decision that matters here: that
 * use case already applies the permission, narrows the filters to the reader's own projects **before** anything
 * is aggregated, and returns an empty result rather than an error for a reader assigned to nothing. An assistant
 * that went to the read model itself would be an assistant with its own copy of those three rules, and the copy
 * is the one that falls behind.
 *
 * The previous period is fetched as its own request rather than taken from the comparison inside the totals,
 * because the per-platform rows are needed for both periods and a comparison does not carry a breakdown.
 */
final readonly class AnalysisAssembler
{
    /**
     * What every analysis reads.
     *
     * Fixed rather than configurable: these are the metrics the analysers are written against, and a set chosen
     * per request would mean findings that silently disappeared when somebody narrowed a filter.
     *
     * @var list<Metric>
     */
    private const array METRICS = [
        Metric::Spend,
        Metric::Impressions,
        Metric::Clicks,
        Metric::Ctr,
        Metric::Cpc,
        Metric::Conversions,
        Metric::CostPerConversion,
    ];

    /** Enough platforms that concentration is visible; few enough that the brief stays readable. */
    private const int PLATFORM_LIMIT = 10;

    public function __construct(
        private GetAnalytics $analytics,
        private TrackingHealthComposer $tracking,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function assemble(string $clientId, DateRange $period): AnalysisContext
    {
        $before = $period->previousPeriod();
        $filters = new AnalyticsFilters(clientId: $clientId);

        $now = ($this->analytics)(new AnalyticsRequest(
            range: $period,
            filters: $filters,
            metrics: self::METRICS,
            compareTo: $before,
            dimension: Dimension::Provider,
            orderBy: Metric::Spend,
            limit: self::PLATFORM_LIMIT,
        ));

        $then = ($this->analytics)(new AnalyticsRequest(
            range: $before,
            filters: $filters,
            metrics: self::METRICS,
            dimension: Dimension::Provider,
            orderBy: Metric::Spend,
            limit: self::PLATFORM_LIMIT,
        ));

        return new AnalysisContext(
            period: $period,
            before: $before,
            totals: $now->summary,
            byProvider: $now->breakdown,
            byProviderBefore: $then->breakdown,
            /*
             * Null when the reader may not see measurement health. The analyser that reads it then says nothing,
             * rather than saying something weaker — a finding about tracking assembled without the tracking data
             * would be a guess wearing a measurement's badge.
             */
            tracking: $this->guard->allows(Permission::TrackingView)
                ? ($this->tracking)(
                    $this->actor->workspaceId()->value,
                    /* The reader's own projects, as everywhere else. The composer holds no permission of its own. */
                    $this->visibility->visibleProjectIds(),
                    $clientId,
                )
                : null,
        );
    }
}
