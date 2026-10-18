<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Command\ProposeBudgetChange;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Domain\Access\Permission;

final readonly class ProposeBudgetChangeTool implements ToolHandler
{
    public function __construct(private ProposeBudgetChange $propose, private ToolCaller $caller) {}

    public function __invoke(array $input): array
    {
        $request = ($this->propose)(
            (string) $input['campaign_id'],
            (int) $input['daily_budget_minor'],
            (string) $input['reason'],
            $this->caller->agent,
        );

        return ['proposal' => ['id' => $request->id, 'title' => $request->title, 'state' => $request->state->value, 'expires_at' => $request->expiresAt?->format(DATE_ATOM)]];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'propose_budget_change',
            description: 'Ask for a campaign’s daily budget to change. Refused above the project’s change limit or ceiling; creates a decision for a person, changes nothing on the platform.',
            action: 'UPDATE_BUDGET',
            permissions: [Permission::ApprovalsRequest, Permission::AnalyticsView],
            risk: 'proposal',
            requiresApproval: true,
            properties: [
                'campaign_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The campaign.'],
                'daily_budget_minor' => ['type' => 'integer', 'min' => 1, 'description' => 'The new daily budget, in minor units of the campaign’s currency.'],
                'reason' => ['type' => 'string', 'description' => 'One sentence: why.'],
            ],
            required: ['campaign_id', 'daily_budget_minor', 'reason'],
            callsPerMinute: 20,
        );
    }
}
