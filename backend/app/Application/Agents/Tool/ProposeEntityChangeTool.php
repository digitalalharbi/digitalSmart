<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Command\ProposeEntityChange;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Operations\DTO\ProposalOrigin;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\AdEntityType;

/**
 * Propose pausing, resuming or re-budgeting one ad group or ad. A proposal, never an action: it writes an approval
 * request a person decides, with the entity's own figures as evidence — and, separately, a person with the write
 * permission carries it out where the platform documents that change at that level.
 */
final readonly class ProposeEntityChangeTool implements ToolHandler
{
    public function __construct(
        private ProposeEntityChange $propose,
        private GetCampaignControl $campaigns,
        private ToolCaller $caller,
        private Clock $clock,
    ) {}

    public function __invoke(array $input): array
    {
        $level = AdEntityType::from((string) $input['level']);
        $id = (string) $input['entity_id'];
        $row = $this->campaigns->assessEntities(
            $level,
            new CampaignControlFilter,
            $level === AdEntityType::AdGroup ? [$id] : [],
            $level === AdEntityType::Ad ? [$id] : [],
        )[0] ?? throw ResourceNotFound::of($level === AdEntityType::Ad ? 'Ad' : 'Ad group');

        /** @var 'pause'|'resume'|'budget' $change */
        $change = (string) $input['change'];
        $request = ($this->propose)(
            $level,
            $id,
            $change,
            isset($input['daily_budget_minor']) ? (int) $input['daily_budget_minor'] : null,
            (string) $input['reason'],
            new ProposalOrigin(
                agent: $this->caller->agent,
                evidence: [
                    'level' => $level->value,
                    'campaign' => $row->campaignName,
                    'currency' => $row->currency,
                    'spend_minor' => $row->spendMinor,
                    'results' => $row->results,
                    'cost_per_result_minor' => $row->costPerResultMinor,
                    'roas' => $row->roas,
                    'reported_days' => $row->reportedDays,
                ],
                risk: 'medium',
                expiresAt: $this->clock->now()->modify('+3 days'),
            ),
        );

        return ['proposal' => ['id' => $request->id, 'title' => $request->title, 'state' => $request->state->value, 'expires_at' => $request->expiresAt?->format(DATE_ATOM)]];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'propose_entity_change',
            description: 'Ask for one ad group or ad to be paused, resumed or (an ad group) given a new daily budget. Refused where the platform does not document that change at that level; creates a decision for a person, changes nothing on the platform.',
            action: 'PROPOSE_ENTITY_CHANGE',
            permissions: [Permission::ApprovalsRequest, Permission::AnalyticsView],
            risk: 'proposal',
            requiresApproval: true,
            properties: [
                'level' => ['type' => 'string', 'enum' => ['ad_group', 'ad'], 'description' => 'Ad group or ad.'],
                'entity_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The ad group or ad.'],
                'change' => ['type' => 'string', 'enum' => ['pause', 'resume', 'budget'], 'description' => 'What to ask for.'],
                'daily_budget_minor' => ['type' => 'integer', 'min' => 1, 'description' => 'For a budget change: the new daily budget, in minor units.'],
                'reason' => ['type' => 'string', 'description' => 'One sentence: why.'],
            ],
            required: ['level', 'entity_id', 'change', 'reason'],
            callsPerMinute: 30,
        );
    }
}
