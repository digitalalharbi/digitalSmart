<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Agents\Query\GetOperatingRules;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class GetOperatingRulesTool implements ToolHandler
{
    public function __construct(private GetOperatingRules $rules) {}

    public function __invoke(array $input): array
    {
        if (! isset($input['client_id']) && ! isset($input['project_id'])) {
            throw ResourceNotFound::of('Scope');
        }

        $r = ($this->rules)(
            isset($input['client_id']) ? (string) $input['client_id'] : null,
            isset($input['project_id']) ? (string) $input['project_id'] : null,
        )['effective'];

        return ['rules' => [
            'primary_kpi' => $r->primaryKpi->value,
            'target_cpa_minor' => $r->targetCpaMinor,
            'target_cpl_minor' => $r->targetCplMinor,
            'target_roas' => $r->targetRoas,
            'min_spend_minor' => $r->minSpendMinor,
            'max_daily_budget_minor' => $r->maxDailyBudgetMinor,
            'max_budget_change_pct' => $r->maxBudgetChangePct,
            'revenue_authority' => $r->revenueAuthority->value,
            'sources' => $r->sources,
        ]];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_operating_rules',
            description: 'The targets and limits in force for a client or project, and which level each came from.',
            action: 'READ_RULES',
            permissions: [Permission::AnalyticsView],
            risk: 'read',
            requiresApproval: false,
            properties: [
                'client_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'A client.'],
                'project_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'A project.'],
            ],
        );
    }
}
