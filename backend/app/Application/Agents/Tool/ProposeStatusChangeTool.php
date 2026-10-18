<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\Command\RequestCampaignStatusChange;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Domain\Access\Permission;
use App\Domain\Integration\WriteOperation;

/**
 * Propose pausing or resuming a campaign. Two tools, one class: `pause` and `resume` differ only in the verb.
 *
 * A proposal, never an action: it writes an approval request that a person decides and, separately, a person with
 * the write permission carries out.
 */
final readonly class ProposeStatusChangeTool implements ToolHandler
{
    public function __construct(
        private RequestCampaignStatusChange $request,
        private StatusEvidence $evidence,
        private ToolCaller $caller,
        private WriteOperation $operation,
    ) {}

    public function __invoke(array $input): array
    {
        $campaignId = (string) $input['campaign_id'];
        $request = ($this->request)(
            $campaignId,
            $this->operation,
            (string) $input['reason'],
            $this->evidence->for($campaignId, $this->caller->agent, 'medium'),
        );

        return ['proposal' => ['id' => $request->id, 'title' => $request->title, 'state' => $request->state->value, 'expires_at' => $request->expiresAt?->format(DATE_ATOM)]];
    }

    public function definition(): ToolDefinition
    {
        $pause = $this->operation === WriteOperation::PauseCampaign;

        return new ToolDefinition(
            name: $pause ? 'propose_pause_campaign' : 'propose_resume_campaign',
            description: $pause
                ? 'Ask for a campaign to be paused. Creates a decision for a person; changes nothing on the platform.'
                : 'Ask for a paused campaign to be resumed. Creates a decision for a person; changes nothing on the platform.',
            action: $pause ? 'PAUSE_CAMPAIGN' : 'RESUME_CAMPAIGN',
            permissions: [Permission::ApprovalsRequest, Permission::AnalyticsView],
            risk: 'proposal',
            requiresApproval: true,
            properties: [
                'campaign_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The campaign.'],
                'reason' => ['type' => 'string', 'description' => 'One sentence: why.'],
            ],
            required: ['campaign_id', 'reason'],
            callsPerMinute: 20,
        );
    }
}
