<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Budget\DTO\PacingView;
use App\Application\Budget\Query\ListBudgetAlerts;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;

/**
 * Budgets that are not going to land where they were committed.
 *
 * Overspend outranks under-delivery. Both are failures of a commitment made to a client, and only one of
 * them is money already gone.
 *
 * A budget whose spend cannot be totalled is raised too, at watch. "We cannot tell you how this is
 * pacing" is itself something somebody needs to know, and leaving it out would make the list look
 * reassuringly short.
 */
final readonly class BudgetAttentionSource implements AttentionSource
{
    public function __construct(private ListBudgetAlerts $alerts) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Budgets;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        return array_map(
            fn (PacingView $alert): AttentionItem => $this->toItem($alert),
            ($this->alerts)(),
        );
    }

    private function toItem(PacingView $alert): AttentionItem
    {
        $pacing = $alert->pacing;

        [$severity, $message] = match (true) {
            $pacing === null => [
                AttentionSeverity::Watch,
                $alert->unavailableReason ?? 'This budget’s spend cannot be totalled, so it is not being paced.',
            ],
            $pacing->risksOverspend() => [
                AttentionSeverity::Critical,
                'Spending ahead of plan. On this trajectory the budget runs out before the period does.',
            ],
            $pacing->risksUnderDelivery() => [
                AttentionSeverity::NeedsAttention,
                'Spending behind plan. On this trajectory the budget will not be used.',
            ],
            default => [AttentionSeverity::Watch, 'Pacing outside the agreed tolerance.'],
        };

        return new AttentionItem(
            id: 'budget:'.$alert->budget->id,
            stream: AttentionStream::Budgets,
            severity: $severity,
            message: $message,
            entityKind: 'Budget',
            entityLabel: $alert->budget->name,
            /* A budget belongs to a client, not to a person. */
            owner: false,
            since: null,
            clientId: $alert->budget->clientId,
            clientName: $alert->budget->clientName,
            projectId: $alert->budget->projectId,
            projectName: $alert->budget->projectName,
        );
    }
}
