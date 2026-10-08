<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Portal\DTO\PortalPerformance;
use App\Application\Portal\Service\PortalAccess;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Analytics\Metric;
use App\Domain\Portal\PortalModule;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * What the advertising did, for the client whose money paid for it.
 *
 * **The metric set is fixed and is not a parameter.** A client reading a surface where they can add metrics will
 * eventually add two that cannot be compared, or one whose denominator is missing, and nobody from the agency will
 * be present to explain the result. The seven here are the ones an agency can stand behind without commentary:
 * the money, the delivery it bought, the two ratios that follow from them, and the conversions with their cost.
 *
 * **Reach and frequency are deliberately absent.** Both are legitimate figures and neither adds up across
 * campaigns, so on a surface that aggregates a client's whole account they would be either wrong or blank with a
 * reason the client cannot act on.
 *
 * **The comparison is always the period before, of equal length.** A client cannot choose it, so it cannot be
 * chosen badly — which is the failure the reporting module spends an entire value object preventing.
 *
 * The figures are the same read model the agency's own analytics uses, with the same source states. A client whose
 * figures come from a provider still awaiting its authorization is shown `SANDBOX`, in those words, for the same
 * reason the agency is.
 */
final readonly class GetPortalPerformance
{
    /**
     * What a client is shown, in this order.
     *
     * @var list<Metric>
     */
    public const array METRICS = [
        Metric::Spend,
        Metric::Impressions,
        Metric::Clicks,
        Metric::Ctr,
        Metric::Cpc,
        Metric::Conversions,
        Metric::CostPerConversion,
    ];

    public function __construct(
        private AnalyticsReadModel $analytics,
        private PortalAccess $access,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $clientId, DateRange $period): PortalPerformance
    {
        $reader = $this->access->ensure($clientId, PortalModule::Performance);

        $workspaceId = $this->actor->workspaceId()->value;
        /*
         * The client id is the only filter. Not the reader's project assignments: a client reads their whole
         * account, and narrowing by the staff member previewing it would make the preview a different document.
         */
        $filters = new AnalyticsFilters(clientId: $clientId);

        $before = $period->previousPeriod();

        return new PortalPerformance(
            reader: $reader,
            period: $period,
            totals: $this->analytics->summary($workspaceId, $period, $filters, self::METRICS, $before),
            series: $this->analytics->series($workspaceId, $period, $filters, [
                Metric::Spend,
                Metric::Clicks,
                Metric::Conversions,
            ]),
            comparisonPeriod: $before,
        );
    }
}
