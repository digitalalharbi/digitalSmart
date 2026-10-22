<?php

declare(strict_types=1);

namespace App\Application\Analytics\Service;

use App\Application\Analytics\Contract\AnalyticsReadModel;
use App\Application\Analytics\Contract\CreativeCatalogue;
use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\BreakdownRow;
use App\Application\Analytics\DTO\CreativeInfo;
use App\Application\Analytics\DTO\CreativeIntelligence;
use App\Application\Analytics\DTO\CreativeRow;
use App\Domain\Analytics\CreativeAssessment;
use App\Domain\Analytics\CreativeAssessor;
use App\Domain\Analytics\CreativeFigures;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Shared\ValueObject\DateRange;

/**
 * The creative read itself, with no opinion about who is asking.
 *
 * The signed-in query authorises the caller and narrows to the projects they can see before handing a scope here; a
 * report renders it for a link nobody is signed in on, with the scope the report was written with. Either way the
 * caller owns the scope — this class only ever reads inside the workspace and filters it is given.
 */
final readonly class CreativeIntelligenceReader
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
    ) {}

    public function read(
        string $workspaceId,
        DateRange $range,
        AnalyticsFilters $filters,
        string $grain = 'asset',
        ?string $format = null,
        ?string $verdict = null,
        int $limit = 24,
        int $offset = 0,
        ?string $adStatus = null,
    ): CreativeIntelligence {
        $grain = $grain === 'ad' ? 'ad' : 'asset';
        $previousRange = $range->previousPeriod();
        $scope = $filters;
        $dimension = $grain === 'ad' ? Dimension::Ad : Dimension::Creative;

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
            function (BreakdownRow $row) use ($info, $assessments, $format, $verdict, $adStatus): bool {
                $about = $info[$row->key] ?? null;

                // A row with no catalogue entry is not this workspace's, or has gone: it is not shown rather than shown unnamed.
                if (! $about instanceof CreativeInfo) {
                    return false;
                }

                if ($format !== null && $about->format !== $format) {
                    return false;
                }

                /* An ad's own status — not its campaign's. At the grain of an asset there is none, so it narrows nothing. */
                if ($adStatus !== null && $about->adStatus !== null && $about->adStatus !== $adStatus) {
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
}
