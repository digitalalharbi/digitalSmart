<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

use App\Application\Advertising\DTO\AdGroupFilter;
use App\Application\Advertising\DTO\AdGroupPage;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\EntityControlRow;
use App\Domain\Advertising\AdEntityType;

/**
 * Every ad group the reader may see, each on its **own** metric rows.
 *
 * An ad group's spend is the figure its platform reports for the ad group, never the sum of its ads and never its
 * campaign's: each level reports the same money once, and reading two levels together counts it twice. So this reads
 * through the same entity assessment the ad-group rules do, and the Ad Groups screen and a rule about an ad group
 * cannot disagree about what one spent.
 *
 * The parent campaign's health rides beside each row, labelled as the campaign's. The product has one judgement of
 * health and it is made about campaigns; giving an ad group a verdict by running that policy over a window with no
 * previous period would produce one that looks the same and means less.
 */
final readonly class ListAdGroups
{
    public function __construct(private GetCampaignControl $campaigns) {}

    public function __invoke(
        CampaignControlFilter $campaignFilter,
        AdGroupFilter $filter,
        ?int $limit = null,
        int $offset = 0,
        ?int $days = null,
    ): AdGroupPage {
        $rows = $this->campaigns->assessEntities(
            AdEntityType::AdGroup,
            $campaignFilter,
            $filter->adGroupIds,
            [],
            $days,
        );
        $isTruncated = count($rows) >= GetCampaignControl::ASSESSMENT_CAP;

        if ($filter->statuses !== []) {
            $wanted = array_map(static fn ($status): string => $status->value, $filter->statuses);
            $rows = array_values(array_filter(
                $rows,
                static fn (EntityControlRow $row): bool => in_array($row->status->value, $wanted, true),
            ));
        }

        if ($filter->search !== null && $filter->search !== '') {
            $needle = mb_strtolower($filter->search);
            $rows = array_values(array_filter(
                $rows,
                static fn (EntityControlRow $row): bool => str_contains(
                    mb_strtolower(implode(' ', [$row->name, $row->campaignName, $row->clientName ?? '', $row->projectName ?? ''])),
                    $needle,
                ),
            ));
        }

        if ($filter->reportedNoResults) {
            $rows = array_values(array_filter(
                $rows,
                static fn (EntityControlRow $row): bool => ($row->spendMinor ?? 0) > 0 && $row->results === 0.0,
            ));
        }

        /* Nulls last whichever way it is sorted: "no figure" is not the cheapest or the dearest result. */
        $key = match ($filter->sort) {
            'name' => static fn (EntityControlRow $r): array => [mb_strtolower($r->name), $r->id],
            'cost' => static fn (EntityControlRow $r): array => [$r->costPerResultMinor === null ? 1 : 0, $r->costPerResultMinor ?? 0.0, $r->name],
            'roas' => static fn (EntityControlRow $r): array => [$r->roas === null ? 1 : 0, -($r->roas ?? 0.0), $r->name],
            'ctr' => static fn (EntityControlRow $r): array => [self::ctr($r) === null ? 1 : 0, -(self::ctr($r) ?? 0.0), $r->name],
            default => static fn (EntityControlRow $r): array => [$r->spendMinor === null ? 1 : 0, -($r->spendMinor ?? 0), $r->name],
        };

        usort($rows, static fn (EntityControlRow $a, EntityControlRow $b): int => $key($a) <=> $key($b));

        if ($filter->descending) {
            $rows = array_reverse($rows);
        }

        $spendByCurrency = [];

        foreach ($rows as $row) {
            if ($row->spendMinor !== null && $row->currency !== null) {
                $spendByCurrency[$row->currency] = ($spendByCurrency[$row->currency] ?? 0) + $row->spendMinor;
            }
        }

        $cap = max(1, min($limit ?? 50, 200));
        $slice = array_slice($rows, max(0, $offset), $cap);

        $parents = [];

        if ($slice !== []) {
            $ids = array_values(array_unique(array_map(static fn (EntityControlRow $r): string => $r->campaignId, $slice)));
            $assessed = $this->campaigns->assessAll(
                new CampaignControlFilter(campaignIds: $ids),
                $days,
            );

            foreach ($assessed['rows'] as $campaign) {
                $parents[$campaign->campaignId] = $campaign;
            }
        }

        return new AdGroupPage(
            rows: $slice,
            campaigns: $parents,
            matched: count($rows),
            isTruncated: $isTruncated,
            spendByCurrency: $spendByCurrency,
            days: GetCampaignControl::window($days),
            limit: $cap,
            offset: max(0, $offset),
        );
    }

    /** Clicks over impressions, or null where nothing was reported to divide by. */
    public static function ctr(EntityControlRow $row): ?float
    {
        return $row->impressions !== null && $row->impressions > 0 && $row->clicks !== null
            ? $row->clicks / $row->impressions
            : null;
    }
}
