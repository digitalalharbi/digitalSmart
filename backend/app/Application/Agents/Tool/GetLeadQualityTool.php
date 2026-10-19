<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Agents\Contract\ToolHandler;
use App\Application\Agents\DTO\ToolDefinition;
use App\Application\Leads\DTO\LeadBreakdownRow;
use App\Application\Leads\DTO\LeadSourceRow;
use App\Application\Leads\Query\GetLeadCentre;
use App\Domain\Access\Permission;

/**
 * A client's leads over a window, kept in their three separate counts: a lead, a qualified lead and a sale are not
 * the same thing and are never added together. No personal data leaves this tool — counts and labels only.
 */
final readonly class GetLeadQualityTool implements ToolHandler
{
    public function __construct(private GetLeadCentre $leads) {}

    public function __invoke(array $input): array
    {
        $view = ($this->leads)((string) $input['client_id'], (int) ($input['days'] ?? 14));
        $f = $view->funnel;

        return [
            'days' => $view->windowDays,
            'leads' => $f->total,
            'contacted' => $f->contacted,
            'reached' => $f->reached,
            'qualified' => $f->qualified,
            'disqualified' => $f->disqualified,
            'won' => $f->won,
            'lost' => $f->lost,
            /* Qualified as a share of leads somebody reached, 0–100: a lead nobody has phoned has not failed to qualify. */
            'qualification_rate' => $f->qualificationRate(),
            'by_source' => array_map(static fn (LeadSourceRow $s): array => [
                'source' => $s->sourceKind->value,
                'detail' => $s->sourceDetail,
                'leads' => $s->total,
                'qualified' => $s->qualified,
                'won' => $s->won,
            ], array_slice($view->bySource, 0, 20)),
            'by_campaign' => array_map(static fn (LeadBreakdownRow $c): array => [
                'campaign' => $c->label,
                'leads' => $c->total,
                'qualified' => $c->qualified,
                'won' => $c->won,
                'attributed' => ! $c->isUnattributed(),
            ], array_slice($view->byCampaign, 0, 20)),
        ];
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: 'get_lead_quality',
            description: 'One client\'s leads, qualified leads and sales over a window, by source and by campaign. Counts only; never contact details.',
            action: 'READ_LEADS',
            permissions: [Permission::LeadsView],
            risk: 'read',
            requiresApproval: false,
            properties: [
                'client_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The client.'],
                'days' => ['type' => 'integer', 'min' => 7, 'max' => 90, 'description' => 'Window ending today (default 14).'],
            ],
            required: ['client_id'],
        );
    }
}
