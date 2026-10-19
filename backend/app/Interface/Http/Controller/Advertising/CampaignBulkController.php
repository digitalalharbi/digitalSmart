<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\Command\BulkCampaignAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignBulkController
{
    public function __invoke(Request $request, BulkCampaignAction $bulk): JsonResponse
    {
        /** @var array{campaign_ids: list<string>, action: 'request_pause'|'request_resume'|'request_budget_change'|'create_task', reason: string, change_pct?: int} $v */
        $v = $request->validate([
            'campaign_ids' => ['required', 'array', 'min:1', 'max:'.BulkCampaignAction::MAX],
            'campaign_ids.*' => ['uuid'],
            'action' => ['required', 'in:request_pause,request_resume,request_budget_change,create_task'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'change_pct' => ['required_if:action,request_budget_change', 'integer', 'min:-50', 'max:50', 'not_in:0'],
        ]);

        return new JsonResponse(['data' => $bulk($v['campaign_ids'], $v['action'], $v['reason'], $v['change_pct'] ?? null)]);
    }
}
