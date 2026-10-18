<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Budget\DTO\BudgetControlRow;
use App\Application\Budget\Query\GetBudgetControl;
use App\Domain\Access\Permission;

final readonly class GetBudgetStatusTool implements ToolHandler
{
    public function __construct(private GetBudgetControl $budgets) {}

    public function __invoke(array $input): array
    {
        $rows = ($this->budgets)(
            isset($input['client_id']) ? (string) $input['client_id'] : null,
            isset($input['project_id']) ? (string) $input['project_id'] : null,
        );

        return ['budgets' => array_map(static fn (BudgetControlRow $b): array => [
            'name' => $b->name,
            'client' => $b->clientName,
            'project' => $b->projectName,
            'currency' => $b->currency,
            'approved_minor' => $b->approvedMinor,
            'allocated_minor' => $b->allocatedMinor,
            'spent_minor' => $b->spentMinor,
            'risks' => $b->risks,
            'unavailable_reason' => $b->unavailableReason,
        ], array_slice($rows, 0, 50))];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_budget_status',
            description: 'Approved, allocated and spent per budget, with the risks each carries. One currency per row; never added across.',
            action: 'READ_BILLING',
            permissions: [Permission::BudgetsView],
            risk: 'read',
            requiresApproval: false,
            properties: [
                'client_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Only this client.'],
                'project_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Only this project.'],
            ],
        );
    }
}
