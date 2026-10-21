<?php

declare(strict_types=1);

namespace App\Application\Analytics\Query;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\Contract\CreativeCatalogue;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\CreativeInfo;
use App\Application\Analytics\DTO\CreativeIntelligence;
use App\Application\Analytics\DTO\CreativeRow;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Analytics\CreativeAssessment;
use App\Domain\Analytics\CreativeAssessor;
use App\Domain\Analytics\CreativeFigures;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
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
    /** The most creatives a selection is judged over. A cohort this size covers the spend of any real account. */
    private const int COHORT = 500;

    private const int MAX_PAGE = 100;

    /** @var list<Metric> */
    private const array METRICS = [
        Metric::Spend, Metric::Impressions, Metric::Clicks, Metric::Ctr, Metric::Cpc, Metric::Conversions,
        Metric::CostPerConversion, Metric::ConversionRate, Metric::Revenue, Metric::Roas, Metric::Frequency,
    ];

    public function __construct(
        private AnalyticsReadModel $analytics,
        private CreativeCatalogue $catalogue,
        private CreativeAssessor $assessor,
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
        $previousRange = $range->previousPeriod();
        $workspaceId = $this->actor->workspaceId()->value;
        $scope = $this->scope($filters);
        $dimension = $grain === 'ad' ? Dimension::Ad : Dimension::Creative;

        if ($scope === null) {
            return new CreativeIntelligence($grain, $range, $previousRange, [], 0, false, [], []);
        }

        $current = $this->analytics->breakdown($workspaceId, $range, $scope, $dimension, self::METRICS, Metric::Spend, self::COHORT);
        $before = $this->analytics->breakdown($workspaceId, $previousRange, $scope, $dimension, self::METRICS, Metric::Spend, self::COHORT);

        $keys = array_map(static fn (BreakdownRow $row): string => $row->key, $current);
        $info = $grain === 'ad' ? $this->catalogue->ads($workspaceId, $keys) : $this->catalogue->assets($workspaceId, $keys);

        $assessments = $this->assessor->assess(
            $this->figures($current),
            $this->figures($before),
        );

        // Counted over the whole selection, before the format and verdict narrow it: a filter's own options must not shrink as it is used.
        $formats = [];
        $platforms = [];

        foreach ($current as $row) {
            $about = $info[$row->key] ?? null;

            if ($about instanceof CreativeInfo) {
                $formats[$about->format] = ($formats[$about->format] ?? 0) + 1;
                $platforms[$about->provider] = ($platforms[$about->provider] ?? 0) + 1;
            }
        }

        $selected = array_values(array_filter(
            $current,
            function (BreakdownRow $row) use ($info, $assessments, $format, $verdict): bool {
                $about = $info[$row->key] ?? null;

                // A row with no catalogue entry is not this workspace's, or has gone: it is not shown rather than shown unnamed.
                if (! $about instanceof CreativeInfo) {
                    return false;
                }

                if ($format !== null && $about->format !== $format) {
                    return false;
                }

                return $verdict === null || ($assessments[$row->key]->verdict->value ?? null) === $verdict;
            },
        ));

        $limit = max(1, min(self::MAX_PAGE, $limit));
        $page = array_slice($selected, max(0, $offset), $limit);
        $pageKeys = array_map(static fn (BreakdownRow $row): string => $row->key, $page);

        $trends = $this->analytics->seriesBy($workspaceId, $range, $scope, $dimension, $pageKeys, [Metric::Spend, Metric::Ctr]);
        $earlier = [];

        foreach ($before as $row) {
            $earlier[$row->key] = $row->metrics;
        }

        $rows = array_map(
            fn (BreakdownRow $row): CreativeRow => new CreativeRow(
                info: $info[$row->key],
                metrics: $row->metrics,
                previous: $earlier[$row->key] ?? null,
                trend: $trends[$row->key] ?? [],
                assessment: $assessments[$row->key] ?? new CreativeAssessment(null),
            ),
            $page,
        );

        return new CreativeIntelligence(
            grain: $grain,
            range: $range,
            previousRange: $previousRange,
            rows: array_values($rows),
            total: count($selected),
            hasMore: count($selected) > max(0, $offset) + $limit,
            formats: $formats,
            platforms: $platforms,
        );
    }

    /**
     * @param  list<BreakdownRow>  $rows
     * @return array<string, CreativeFigures>
     */
    private function figures(array $rows): array
    {
        $figures = [];

        foreach ($rows as $row) {
            $value = static function (Metric $metric) use ($row): ?float {
                $raw = $row->metrics->get($metric)?->value;

                return $raw === null ? null : (float) $raw;
            };

            $figures[$row->key] = new CreativeFigures(
                impressions: $value(Metric::Impressions),
                spend: $value(Metric::Spend),
                ctr: $value(Metric::Ctr),
                frequency: $value(Metric::Frequency),
                costPerResult: $value(Metric::CostPerConversion),
                results: $value(Metric::Conversions),
                conversionRate: $value(Metric::ConversionRate),
            );
        }

        return $figures;
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
