<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Analytics;

use App\Application\Analytics\DTO\CreativeIntelligence;
use App\Application\Analytics\DTO\CreativeRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Advertising\CreativeFormat;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;

/**
 * Creatives as the interface reads them.
 *
 * Every field is one the data actually has. A figure a platform does not report is `null` with the reason the analytics
 * layer gave, never a zero; a preview that cannot honestly be shown is `state: none` or `fixture` with no address; and no
 * provider identifier, token or account id appears anywhere in a row.
 */
final readonly class CreativeIntelligenceResource
{
    /** @return array<string, mixed> */
    public static function toArray(CreativeIntelligence $result): array
    {
        return [
            'grain' => $result->grain,
            'range' => [
                'start' => $result->range->startDate(),
                'end' => $result->range->endDate(),
                'days' => $result->range->lengthInDays(),
            ],
            'compared_with' => [
                'start' => $result->previousRange->startDate(),
                'end' => $result->previousRange->endDate(),
            ],
            'total' => $result->total,
            'has_more' => $result->hasMore,
            'facets' => [
                'formats' => self::facet($result->formats, static fn (string $key): string => CreativeFormat::tryFrom($key)?->label() ?? $key),
                'platforms' => self::facet($result->platforms, static fn (string $key): string => Provider::tryFrom($key)?->label() ?? $key),
            ],
            'rows' => array_map(self::row(...), $result->rows),
        ];
    }

    /**
     * @param  array<string, int>  $counts
     * @param  callable(string): string  $label
     * @return list<array{key: string, label: string, count: int}>
     */
    private static function facet(array $counts, callable $label): array
    {
        arsort($counts);

        return array_values(array_map(
            static fn (string $key, int $count): array => ['key' => $key, 'label' => $label($key), 'count' => $count],
            array_keys($counts),
            $counts,
        ));
    }

    /** @return array<string, mixed> */
    private static function row(CreativeRow $row): array
    {
        $info = $row->info;

        return [
            'key' => $info->key,
            'label' => $info->name,
            'platform' => $info->provider,
            'platform_label' => Provider::tryFrom($info->provider)?->label() ?? $info->provider,
            'format' => $info->format,
            'format_label' => CreativeFormat::tryFrom($info->format)?->label() ?? $info->format,
            'media' => [
                'state' => $info->media->state,
                'kind' => $info->media->kind,
                'url' => $info->media->url,
                'origin' => $info->media->origin,
            ],
            'ads' => $info->adCount,
            'campaigns' => $info->campaigns,
            'campaign_count' => $info->campaignCount,
            'ad_name' => $info->adName,
            'ad_status' => $info->adStatus,
            'asset_key' => $info->assetKey,
            /* At the grain of an ad only: where it sits. An asset is not one ad, so it has no single parent. */
            'parents' => $info->adStatus === null ? null : [
                'ad_group' => $info->adGroupId === null ? null : ['id' => $info->adGroupId, 'name' => $info->adGroupName],
                'campaign' => $info->campaignId === null ? null : ['id' => $info->campaignId, 'name' => $info->campaigns[0] ?? null],
            ],
            'metrics' => self::set($row->metrics),
            'trend' => array_map(
                static fn (SeriesPoint $point): array => [
                    'date' => $point->date->format('Y-m-d'),
                    'spend' => $point->metrics->get(Metric::Spend)?->value,
                    'ctr' => $point->metrics->get(Metric::Ctr)?->value,
                ],
                $row->trend,
            ),
            'verdict' => $row->assessment->verdict?->value,
            'verdict_label' => $row->assessment->verdict?->label(),
            'evidence' => $row->assessment->evidence,
        ];
    }

    /** @return array<string, mixed> */
    private static function set(MetricSet $set): array
    {
        return [
            'source' => $set->source->value,
            'source_label' => $set->source->label(),
            'currency' => $set->currency,
            'values' => array_map(
                static fn (object $value): array => MetricValueResource::toArray($value),
                $set->values,
            ),
        ];
    }
}
