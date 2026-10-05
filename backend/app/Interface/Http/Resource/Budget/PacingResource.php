<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Budget;

use App\Application\Budget\DTO\AllocationPacingView;
use App\Application\Budget\DTO\PacingView;
use App\Application\Budget\DTO\SpendTotal;
use App\Domain\Budget\Pacing;

final readonly class PacingResource
{
    /** @return array<string, mixed> */
    public static function toArray(PacingView $view): array
    {
        return [
            'budget' => BudgetResource::toArray($view->budget),
            'spend' => self::spend($view->spend),
            'pacing' => $view->pacing === null ? null : self::pacing($view->pacing),
            /*
             * Why there is no pacing, when there is none. A client screen showing an
             * empty pacing panel with no explanation is how a reader concludes the spend
             * was zero.
             */
            'unavailable_reason' => $view->unavailableReason,
            'allocations' => array_map(
                static fn (AllocationPacingView $row): array => self::allocationRow($row),
                $view->allocations,
            ),
            /*
             * Day by day, for the pacing chart. Only the days a provider reported: a
             * missing day is drawn as a gap rather than filled with a zero that would
             * flatten the cumulative line.
             */
            'daily' => $view->daily,
        ];
    }

    /** @return array<string, mixed> */
    private static function spend(SpendTotal $spend): array
    {
        return [
            'minor_units' => $spend->minorUnits,
            'currency' => $spend->currency,
            // The honesty label: live, synced, stale, partial, sandbox, manual, estimated.
            'source' => $spend->source->value,
            'source_label' => $spend->source->label(),
            'unavailable_reason' => $spend->unavailableReason,
            'rows_reported' => $spend->rowCount,
        ];
    }

    /** @return array<string, mixed> */
    private static function pacing(Pacing $pacing): array
    {
        return [
            'status' => $pacing->status->value,
            'status_label' => $pacing->status->label(),
            'needs_attention' => $pacing->status->needsAttention(),
            'budget_minor' => $pacing->budgetMinor,
            'spent_minor' => $pacing->spentMinor,
            'expected_minor' => $pacing->expectedMinor,
            'variance_minor' => $pacing->varianceMinor,
            'variance_percent' => $pacing->variancePercent,
            'remaining_minor' => $pacing->remainingMinor,
            /*
             * Null before the period starts, because there is nothing to extrapolate
             * from. A zero here would read as a forecast of no spend.
             */
            'projected_minor' => $pacing->projectedMinor,
            'daily_allowance_minor' => $pacing->dailyAllowanceMinor,
            'total_days' => $pacing->totalDays,
            'elapsed_days' => $pacing->elapsedDays,
            'remaining_days' => $pacing->remainingDays,
            'utilisation' => $pacing->utilisation(),
            'period_elapsed' => $pacing->periodElapsed(),
            'risks_overspend' => $pacing->risksOverspend(),
            'risks_under_delivery' => $pacing->risksUnderDelivery(),
            'currency' => $pacing->currency,
            // Published so a reader knows what "expected" means without a footnote.
            'tolerance' => Pacing::TOLERANCE,
        ];
    }

    /** @return array<string, mixed> */
    private static function allocationRow(AllocationPacingView $row): array
    {
        return [
            'allocation_id' => $row->allocation->id,
            'level' => $row->allocation->level->value,
            'level_label' => $row->allocation->level->label(),
            'provider' => $row->allocation->provider->value,
            'label' => $row->allocation->label,
            'parent_label' => $row->parentLabel,
            'amount_minor' => $row->allocation->amountMinor,
            'currency' => $row->allocation->currency,
            'spend' => self::spend($row->spend),
            'pacing' => $row->pacing === null ? null : self::pacing($row->pacing),
            'unavailable_reason' => $row->unavailableReason,
        ];
    }
}
