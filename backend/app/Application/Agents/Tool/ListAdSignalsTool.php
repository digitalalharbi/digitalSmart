<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\Query\ListAdSignals;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Domain\Access\Permission;

final readonly class ListAdSignalsTool implements ToolHandler
{
    public function __construct(private ListAdSignals $signals) {}

    public function __invoke(array $input): array
    {
        $result = ($this->signals)(
            isset($input['project_id']) ? (string) $input['project_id'] : null,
            (int) ($input['limit'] ?? 50),
            (int) ($input['offset'] ?? 0),
            (int) ($input['min_impressions'] ?? 1000),
        );

        return [
            'ads' => array_map(static fn (array $a): array => [
                'id' => $a['id'],
                'name' => $a['name'],
                'campaign_id' => $a['campaign_id'],
                'campaign' => $a['campaign_name'],
                'client_id' => $a['client_id'],
                'project_id' => $a['project_id'],
                'provider' => $a['provider'],
                'creative' => $a['creative'],
                'impressions_now' => $a['impressions_now'],
                'spend_now_minor' => $a['spend_now_minor'],
                'ctr_now' => $a['ctr_now'],
                'ctr_before' => $a['ctr_before'],
                'ctr_change' => $a['ctr_change'],
                'frequency' => $a['frequency'],
            ], $result['ads']),
            'campaigns' => $result['campaigns'],
            'matched' => $result['matched'],
        ];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'list_ad_signals',
            description: 'Ads under the caller\'s live campaigns: click-through this week against the three weeks before, frequency and the creative. Figures only.',
            action: 'READ_CAMPAIGNS',
            permissions: [Permission::AnalyticsView],
            risk: 'read',
            requiresApproval: false,
            properties: [
                'project_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Only this project.'],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 100, 'description' => 'Campaigns to read (default 50).'],
                'offset' => ['type' => 'integer', 'min' => 0, 'max' => 5000, 'description' => 'Skip this many campaigns.'],
                'min_impressions' => ['type' => 'integer', 'min' => 0, 'max' => 1000000, 'description' => 'Leave out ads seen fewer times this week (default 1000).'],
            ],
        );
    }
}
