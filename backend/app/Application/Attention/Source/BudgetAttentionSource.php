<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Budget\DTO\BudgetControlRow;
use App\Application\Budget\Query\GetBudgetControl;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;

/**
 * Budgets carrying a risk, read from the same aggregate the finance surface uses.
 *
 * Previously this called the per-budget alert list, which paces each budget with several statements of
 * its own — the single biggest share of the queue's cost, and a rule-71 violation sitting behind a
 * home-screen widget. It now reads `GetBudgetControl`: three statements however many budgets there are,
 * and by construction the same risks the finance page shows, because they are the same computation.
 *
 * One item per risk rather than per budget. A budget that is overspending *and* has unallocated money is
 * two different pieces of work for two different people.
 */
final readonly class BudgetAttentionSource implements AttentionSource
{
    private const array SEVERITY = [
        'overspend' => AttentionSeverity::Critical,
        'underpace' => AttentionSeverity::NeedsAttention,
        'near_limit' => AttentionSeverity::NeedsAttention,
        'platform_over' => AttentionSeverity::NeedsAttention,
        'campaign_over' => AttentionSeverity::NeedsAttention,
        'unallocated' => AttentionSeverity::Watch,
    ];

    private const array MESSAGE = [
        'overspend' => 'Spending ahead of plan. On this trajectory the budget runs out before the period does.',
        'underpace' => 'Spending behind plan. On this trajectory the budget will not be used.',
        'near_limit' => 'Close to the approved amount with the period still running.',
        'platform_over' => 'A platform has spent past the amount allocated to it.',
        'campaign_over' => 'A campaign has spent past the amount allocated to it.',
        'unallocated' => 'Approved money that has not been allocated to a platform yet.',
    ];

    public function __construct(private GetBudgetControl $control) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Budgets;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $items = [];

        foreach (($this->control)() as $row) {
            foreach ($row->risks as $risk) {
                $items[] = $this->item($row, $risk);
            }
        }

        return $items;
    }

    private function item(BudgetControlRow $row, string $risk): AttentionItem
    {
        return new AttentionItem(
            id: sprintf('budget:%s:%s', $row->budgetId, $risk),
            stream: AttentionStream::Budgets,
            severity: self::SEVERITY[$risk],
            message: self::MESSAGE[$risk],
            entityKind: 'Budget',
            entityLabel: $row->name,
            /* A budget belongs to a client, not to a person. */
            owner: false,
            since: null,
            clientId: $row->clientId,
            clientName: $row->clientName,
            projectId: $row->projectId,
            projectName: $row->projectName,
        );
    }
}
