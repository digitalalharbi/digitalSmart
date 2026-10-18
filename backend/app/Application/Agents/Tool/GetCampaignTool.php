<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

final readonly class GetCampaignTool implements ToolHandler
{
    public function __construct(private GetCampaignControl $campaigns) {}

    public function __invoke(array $input): array
    {
        $page = ($this->campaigns)(new CampaignControlFilter(campaignIds: [(string) $input['campaign_id']]), 1);
        $row = $page->rows[0] ?? throw ResourceNotFound::of('Campaign');

        return ['campaign' => CampaignShape::of($row)];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_campaign',
            description: 'One campaign: its health verdict and the reason, spend, results, cost per result and budget.',
            action: 'READ_CAMPAIGNS',
            permissions: [Permission::AnalyticsView],
            risk: 'read',
            requiresApproval: false,
            properties: ['campaign_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The campaign.']],
            required: ['campaign_id'],
        );
    }
}
