<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Budget;

use App\Application\Budget\DTO\AllocationView;
use App\Application\Budget\DTO\BudgetView;

final readonly class BudgetResource
{
    /**
     * @param  bool  $includeNotes  false for anything a client can read
     * @return array<string, mixed>
     */
    public static function toArray(BudgetView $budget, bool $includeNotes = true): array
    {
        return [
            'id' => $budget->id,
            'client_id' => $budget->clientId,
            'client_name' => $budget->clientName,
            'project_id' => $budget->projectId,
            'project_name' => $budget->projectName,
            'name' => $budget->name,
            'period_start' => $budget->periodStart->format('Y-m-d'),
            'period_end' => $budget->periodEnd->format('Y-m-d'),
            'amount_minor' => $budget->amountMinor,
            'currency' => $budget->currency,
            'status' => $budget->status->value,
            'status_label' => $budget->status->label(),
            /*
             * Published so the interface disables a control for the same reason the
             * server would refuse it, rather than keeping its own copy of the rule.
             */
            'amount_is_editable' => $budget->status->allowsAmountChange(),
            'allocations_are_editable' => $budget->status->allowsAllocationChange(),
            'is_paced' => $budget->status->isPaced(),
            'approved_by_name' => $budget->approvedByName,
            'approved_at' => $budget->approvedAt?->format(DATE_ATOM),
            'allocated_minor' => $budget->allocatedMinor(),
            /*
             * An unallocated remainder is a decision nobody has made yet, not an error.
             * Published because seeing it is how someone remembers to make it.
             */
            'unallocated_minor' => $budget->unallocatedMinor(),
            'allocations' => array_map(
                static fn (AllocationView $allocation): array => self::allocation($allocation),
                $budget->allocations,
            ),
            // Internal, and absent rather than null when a reader may not have it.
            ...($includeNotes ? ['notes' => $budget->notes] : []),
            'updated_at' => $budget->updatedAt?->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private static function allocation(AllocationView $allocation): array
    {
        return [
            'id' => $allocation->id,
            'level' => $allocation->level->value,
            'level_label' => $allocation->level->label(),
            'provider' => $allocation->provider->value,
            'provider_label' => $allocation->provider->label(),
            'label' => $allocation->label,
            'amount_minor' => $allocation->amountMinor,
            'currency' => $allocation->currency,
            'external_account_id' => $allocation->externalAccountId,
            'campaign_id' => $allocation->campaignId,
            'campaign_name' => $allocation->campaignName,
            'ad_group_id' => $allocation->adGroupId,
            'ad_group_name' => $allocation->adGroupName,
            'unallocated_minor' => $allocation->unallocatedMinor(),
            'children' => array_map(
                static fn (AllocationView $child): array => self::allocation($child),
                $allocation->children,
            ),
        ];
    }
}
