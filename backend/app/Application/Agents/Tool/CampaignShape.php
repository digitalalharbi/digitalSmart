<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\DTO\CampaignControlRow;

/** One campaign as a tool returns it: the same figures the screens show, with null kept distinct from zero. */
final class CampaignShape
{
    /** @return array<string, mixed> */
    public static function of(CampaignControlRow $row): array
    {
        return [
            'id' => $row->campaignId,
            'name' => $row->campaignName,
            'provider' => $row->provider,
            'status' => $row->status->value,
            'health' => $row->health->value,
            'health_reason' => $row->healthReason,
            'health_reason_code' => $row->healthReasonCode,
            'health_reason_params' => $row->healthReasonParams,
            'client' => $row->clientName,
            'client_id' => $row->clientId,
            'project' => $row->projectName,
            'project_id' => $row->projectId,
            'currency' => $row->currency,
            'spend_minor' => $row->spendMinor,
            'results' => $row->results,
            'result_label' => $row->resultLabel,
            'cost_per_result_minor' => $row->costPerResultMinor,
            'roas' => $row->roas,
            'budget_minor' => $row->budgetMinor,
            'budget_utilisation' => $row->budgetUtilisation,
            'reported_days' => $row->reportedDays,
        ];
    }
}
