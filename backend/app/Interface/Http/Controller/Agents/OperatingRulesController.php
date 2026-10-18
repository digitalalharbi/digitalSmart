<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Agents;

use App\Application\Agents\Command\SaveOperatingRules;
use App\Application\Agents\DTO\OperatingRules;
use App\Application\Agents\Query\GetOperatingRules;
use App\Domain\Agents\PrimaryKpi;
use App\Domain\Agents\RevenueAuthority;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Targets and limits agents work to. One scope per request: a client, or a project. */
final class OperatingRulesController extends Controller
{
    private const FIELDS = ['primary_kpi', 'target_cpa_minor', 'target_cpl_minor', 'target_roas', 'min_spend_minor', 'max_daily_budget_minor', 'max_budget_change_pct', 'revenue_authority', 'forbidden_terms', 'important_dates'];

    public function show(Request $request, GetOperatingRules $get): JsonResponse
    {
        $v = $request->validate([
            'client_id' => ['required_without:project_id', 'nullable', 'uuid'],
            'project_id' => ['required_without:client_id', 'nullable', 'uuid'],
        ]);

        $result = $get($v['client_id'] ?? null, $v['project_id'] ?? null);

        return response()->json(['data' => [
            'effective' => $this->present($result['effective']),
            'own' => $result['own'] === null ? null : array_intersect_key($result['own'], array_flip(self::FIELDS)),
        ]]);
    }

    public function update(Request $request, SaveOperatingRules $save): JsonResponse
    {
        $v = $request->validate([
            'client_id' => ['required_without:project_id', 'nullable', 'uuid'],
            'project_id' => ['required_without:client_id', 'nullable', 'uuid'],
            'primary_kpi' => ['sometimes', 'nullable', 'in:'.implode(',', PrimaryKpi::values())],
            'target_cpa_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'target_cpl_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'target_roas' => ['sometimes', 'nullable', 'numeric', 'min:0.1', 'max:1000'],
            'min_spend_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'max_daily_budget_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'max_budget_change_pct' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'revenue_authority' => ['sometimes', 'nullable', 'in:'.implode(',', RevenueAuthority::values())],
            'forbidden_terms' => ['sometimes', 'array', 'max:100'],
            'forbidden_terms.*' => ['string', 'max:80'],
            'important_dates' => ['sometimes', 'array', 'max:50'],
            'important_dates.*.date' => ['required', 'date_format:Y-m-d'],
            'important_dates.*.label' => ['required', 'string', 'max:120'],
        ]);

        $client = $v['client_id'] ?? null;
        $project = $v['project_id'] ?? null;
        unset($v['client_id'], $v['project_id']);

        $save(is_string($client) ? $client : null, is_string($project) ? $project : null, $v);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(OperatingRules $r): array
    {
        return [
            'primary_kpi' => $r->primaryKpi->value,
            'target_cpa_minor' => $r->targetCpaMinor,
            'target_cpl_minor' => $r->targetCplMinor,
            'target_roas' => $r->targetRoas,
            'min_spend_minor' => $r->minSpendMinor,
            'max_daily_budget_minor' => $r->maxDailyBudgetMinor,
            'max_budget_change_pct' => $r->maxBudgetChangePct,
            'revenue_authority' => $r->revenueAuthority->value,
            'forbidden_terms' => $r->forbiddenTerms,
            'important_dates' => $r->importantDates,
            'sources' => $r->sources,
        ];
    }
}
