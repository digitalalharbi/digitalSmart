<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Domain\Access\Permission;
use App\Domain\Advertising\CampaignHealth;

final readonly class ListCampaignsTool implements ToolHandler
{
    public function __construct(private GetCampaignControl $campaigns) {}

    public function __invoke(array $input): array
    {
        $health = isset($input['health']) ? CampaignHealth::tryFrom((string) $input['health']) : null;
        $page = ($this->campaigns)(
            new CampaignControlFilter(
                clientIds: isset($input['client_id']) ? [(string) $input['client_id']] : [],
                projectIds: isset($input['project_id']) ? [(string) $input['project_id']] : [],
                health: $health === null ? [] : [$health],
            ),
            (int) ($input['limit'] ?? 20),
        );

        return [
            'campaigns' => array_map(static fn (CampaignControlRow $r): array => CampaignShape::of($r), $page->rows),
            'matched' => $page->matched,
        ];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'list_campaigns',
            description: 'The campaigns the caller may see, worst health first, with spend, results and cost per result.',
            action: 'READ_CAMPAIGNS',
            permissions: [Permission::AnalyticsView],
            risk: 'read',
            requiresApproval: false,
            properties: [
                'client_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Only this client.'],
                'project_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Only this project.'],
                'health' => ['type' => 'string', 'enum' => CampaignHealth::values(), 'description' => 'Only campaigns in this health state.'],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 50, 'description' => 'How many to return (default 20).'],
            ],
        );
    }
}
