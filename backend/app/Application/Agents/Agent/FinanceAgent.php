<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\Tool\ToolGateway;

/**
 * The Finance agent: reads budgets and, where one is over, puts a task in front of a person.
 *
 * It does not propose a budget change itself — an overspent envelope is a conversation with the client, not a
 * number to nudge — and a task is the one write it makes, which is internal and reversible. One task per budget, never
 * repeated while an earlier one is open (the board's own duplicate check is by title).
 */
final readonly class FinanceAgent
{
    /** A morning's worth: past this the list stops being a to-do list and becomes the problem. */
    private const int TASKS_PER_RUN = 5;

    /** @return array{looked_at: int, tasks: int} */
    public function act(ToolGateway $tools, string $agent): array
    {
        $budgets = $tools->call('get_budget_status', [], 'agent', $agent);
        $rows = $budgets['ok'] ? ($budgets['data']['budgets'] ?? []) : [];
        $tasks = 0;

        foreach ($rows as $row) {
            $risks = $row['risks'] ?? [];

            if (! in_array('overspend', $risks, true) && ! in_array('campaign_over', $risks, true)) {
                continue;
            }

            $title = sprintf('Review overspend: %s — %s', (string) ($row['client'] ?? ''), (string) ($row['name'] ?? ''));
            $made = $tools->call('create_task', ['title' => $title], 'agent', $agent);

            if ($made['ok'] && ++$tasks >= self::TASKS_PER_RUN) {
                break;
            }
        }

        return ['looked_at' => count($rows), 'tasks' => $tasks];
    }
}
