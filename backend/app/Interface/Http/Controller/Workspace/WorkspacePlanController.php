<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Workspace;

use App\Application\Saas\DTO\OverrideRecord;
use App\Application\Saas\Query\GetWorkspacePlan;
use App\Interface\Http\Controller\Controller;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;

final class WorkspacePlanController extends Controller
{
    public function __invoke(GetWorkspacePlan $plan): JsonResponse
    {
        $view = $plan();

        return response()->json(['data' => [
            'plan' => ['code' => $view->planCode, 'name' => $view->planName],
            'features' => $view->features,
            'limits' => array_map(static fn (array $l): array => [
                ...$l,
                'resets_at' => $l['resets_at']?->format(DateTimeInterface::ATOM),
            ], $view->limits),
            'overrides' => array_map(static fn (OverrideRecord $o): array => [
                'kind' => $o->kind,
                'key' => $o->key,
                'value' => $o->value,
                'previous_value' => $o->previousValue,
                'reason' => $o->reason,
                'changed_by' => $o->changedBy,
                'at' => $o->at->format(DateTimeInterface::ATOM),
            ], $view->overrides),
        ]]);
    }
}
