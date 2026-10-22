<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Advertising;

use App\Application\Advertising\DTO\AdGroupPage;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\DTO\EntityControlRow;
use App\Application\Advertising\Query\ListAdGroups;
use App\Domain\Advertising\EntityStatus;
use stdClass;

final readonly class AdGroupResource
{
    /** @return array<string, mixed> */
    public static function row(EntityControlRow $row, ?CampaignControlRow $campaign): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'provider' => $row->provider,
            'campaign' => ['id' => $row->campaignId, 'name' => $row->campaignName],
            'client_id' => $row->clientId,
            'client_name' => $row->clientName,
            'project_id' => $row->projectId,
            'project_name' => $row->projectName,
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'objective' => $row->objective->value,
            'objective_label' => $row->objective->label(),
            'currency' => $row->currency,
            'budget_minor' => $row->budgetMinor,
            'budget_utilisation' => $row->budgetUtilisation,
            'spend_minor' => $row->spendMinor,
            'result' => $row->results,
            'result_label' => $row->objective->primaryMetric(),
            'cost_per_result_minor' => $row->costPerResultMinor,
            'revenue_minor' => $row->revenueMinor,
            'roas' => $row->roas,
            'impressions' => $row->impressions,
            'clicks' => $row->clicks,
            'ctr' => ListAdGroups::ctr($row),
            'starts_on' => $row->startedAt?->format('Y-m-d'),
            'ends_on' => $row->endsAt?->format('Y-m-d'),
            'reported_days' => $row->reportedDays,

            /* The parent campaign's own verdict, named as such. An ad group has none of its own. */
            'campaign_health' => $campaign === null ? null : [
                'health' => $campaign->health->value,
                'label' => $campaign->health->label(),
                'reason' => $campaign->healthReason,
                'reason_code' => $campaign->healthReasonCode,
                'reason_params' => $campaign->healthReasonParams === [] ? new stdClass : $campaign->healthReasonParams,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function page(AdGroupPage $page): array
    {
        return [
            'data' => array_map(
                static fn (EntityControlRow $row): array => self::row($row, $page->campaigns[$row->campaignId] ?? null),
                $page->rows,
            ),
            'meta' => [
                'matched' => $page->matched,
                'is_truncated' => $page->isTruncated,
                'days' => $page->days,
                'limit' => $page->limit,
                'offset' => $page->offset,
                'spend_by_currency' => array_map(
                    static fn (string $currency, int $minor): array => ['currency' => $currency, 'spend_minor' => $minor],
                    array_keys($page->spendByCurrency),
                    array_values($page->spendByCurrency),
                ),
                'statuses' => array_map(
                    static fn (EntityStatus $status): array => ['status' => $status->value, 'label' => $status->label()],
                    EntityStatus::cases(),
                ),
            ],
        ];
    }
}
