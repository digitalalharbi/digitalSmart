<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Advertising;

use App\Application\Advertising\DTO\CampaignControlPage;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;
use DateTimeInterface;
use stdClass;

final readonly class CampaignControlResource
{
    /** @return array<string, mixed> */
    public static function row(CampaignControlRow $row): array
    {
        return [
            'id' => $row->campaignId,
            'name' => $row->campaignName,
            'provider' => $row->provider,
            'external_account' => [
                'id' => $row->externalAccountId,
                'name' => $row->externalAccountName,
            ],
            'client_id' => $row->clientId,
            'client_name' => $row->clientName,
            'project_id' => $row->projectId,
            'project_name' => $row->projectName,
            'ownership' => $row->ownership->value,
            'ownership_label' => $row->ownership->label(),

            /*
             * Both states, side by side and never merged. The platform's word is what somebody will see
             * if they open Meta; ours is what they should do about it. Rule 11 is explicit that a
             * campaign can be ACTIVE there and critical here, and that row is the point of the surface.
             */
            'status' => $row->status->value,
            'status_label' => $row->status->label(),
            'provider_status' => $row->rawStatus,
            'health' => $row->health->value,
            'health_label' => $row->health->label(),
            'health_reason' => $row->healthReason,
            'health_reason_code' => $row->healthReasonCode,
            'health_reason_params' => $row->healthReasonParams === [] ? new stdClass : $row->healthReasonParams,
            'needs_attention' => $row->health->needsAttention(),

            'objective' => $row->objective->value,
            'objective_label' => $row->objective->label(),
            'currency' => $row->currency,
            'budget_minor' => $row->budgetMinor,
            'spend_minor' => $row->spendMinor,

            /*
             * The objective's own result, named by what it counts. A reach campaign is never ranked on
             * purchases it was not built to drive, and `cost_per_result_minor` is null where dividing
             * spend by the figure would be inventing a metric nobody uses.
             */
            'result' => $row->results,
            'result_label' => $row->resultLabel,
            'cost_per_result_minor' => $row->costPerResultMinor,

            /* Null for every objective that does not measure revenue, whatever the column holds. */
            'revenue_minor' => $row->revenueMinor,
            'roas' => $row->roas,

            /* Null unless the budget is daily; a lifetime budget has no daily line to measure against. */
            'budget_utilisation' => $row->budgetUtilisation,
            'trend' => $row->trend,
            'reported_days' => $row->reportedDays,
            'last_synced_at' => $row->lastSyncedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function page(CampaignControlPage $page): array
    {
        return [
            'data' => array_map(
                static fn (CampaignControlRow $row): array => self::row($row),
                $page->rows,
            ),
            'meta' => [
                'health_counts' => $page->healthCounts,
                'spend_by_currency' => array_map(
                    static fn (string $currency, int $minor): array => ['currency' => $currency, 'spend_minor' => $minor],
                    array_keys($page->spendByCurrency),
                    array_values($page->spendByCurrency),
                ),
                'spend_trend' => array_map(
                    static fn (string $currency, array $points): array => ['currency' => $currency, 'points' => $points],
                    array_keys($page->spendTrend),
                    array_values($page->spendTrend),
                ),
                'total' => $page->total,
                'matched' => $page->matched,
                'active_count' => $page->activeCount,
                'assessed' => $page->assessed,
                /*
                 * True when the verdicts cover only part of the agency. The interface must say so rather
                 * than present the counts as a picture of everything.
                 */
                'is_truncated' => $page->isTruncated,
                'window' => [
                    'from' => $page->from->format('Y-m-d'),
                    'to' => $page->to->format('Y-m-d'),
                ],
                'limit' => $page->limit,
                'offset' => $page->offset,
            ] + self::vocabulary(),
        ];
    }

    /**
     * Health, status and objective, published rather than hard-coded in the client.
     *
     * @return array<string, mixed>
     */
    public static function vocabulary(): array
    {
        return [
            'health' => array_map(static fn (CampaignHealth $state): array => [
                'health' => $state->value,
                'label' => $state->label(),
                'needs_attention' => $state->needsAttention(),
                'rank' => $state->rank(),
            ], CampaignHealth::cases()),
            'statuses' => array_map(static fn (EntityStatus $status): array => [
                'status' => $status->value,
                'label' => $status->label(),
            ], EntityStatus::cases()),
            'objectives' => array_map(static fn (CampaignObjective $objective): array => [
                'objective' => $objective->value,
                'label' => $objective->label(),
                'result_label' => $objective->primaryMetric(),
                'measures_revenue' => $objective->measuresRevenue(),
                'has_cost_per_result' => $objective->hasCostPerResult(),
            ], CampaignObjective::cases()),
        ];
    }
}
