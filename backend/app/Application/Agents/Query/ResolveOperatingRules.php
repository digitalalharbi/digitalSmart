<?php

declare(strict_types=1);

namespace App\Application\Agents\Query;

use App\Application\Agents\Contract\OperatingRuleRepository;
use App\Application\Agents\DTO\OperatingRules;
use App\Domain\Agents\PrimaryKpi;
use App\Domain\Agents\RevenueAuthority;

/**
 * The rules in force for a project (or a client): its own, else its client's, else the agency's defaults.
 *
 * Pure resolution, no permission check of its own — callers have already established that the actor may see the
 * project, and an agent must be able to read the rules of the project it is looking at.
 */
final readonly class ResolveOperatingRules
{
    /** The only agency-wide default that carries a number: how far one change may move a budget. */
    public const int DEFAULT_MAX_CHANGE_PCT = 20;

    public function __construct(private OperatingRuleRepository $rules) {}

    public function __invoke(string $workspaceId, ?string $clientId, ?string $projectId = null): OperatingRules
    {
        if ($projectId !== null && $clientId === null) {
            $clientId = $this->rules->clientOf($workspaceId, $projectId);
        }

        $layers = [];

        if ($projectId !== null) {
            $layers['project'] = $this->rules->own($workspaceId, null, $projectId);
        }

        if ($clientId !== null) {
            $layers['client'] = $this->rules->own($workspaceId, $clientId, null);
        }

        $sources = [];
        $pick = static function (string $field) use ($layers, &$sources): mixed {
            foreach ($layers as $scope => $row) {
                if ($row !== null && ($row[$field] ?? null) !== null) {
                    $sources[$field] = $scope;

                    return $row[$field];
                }
            }

            $sources[$field] = 'agency_default';

            return null;
        };

        $kpi = $pick('primary_kpi');
        $roas = $pick('target_roas');
        $cpa = $pick('target_cpa_minor');
        $cpl = $pick('target_cpl_minor');
        $min = $pick('min_spend_minor');
        $maxBudget = $pick('max_daily_budget_minor');
        $change = $pick('max_budget_change_pct');
        $authority = $pick('revenue_authority');
        $terms = $pick('forbidden_terms');
        $dates = $pick('important_dates');

        return new OperatingRules(
            primaryKpi: PrimaryKpi::tryFrom((string) $kpi) ?? PrimaryKpi::Roas,
            targetCpaMinor: $cpa === null ? null : (int) $cpa,
            targetCplMinor: $cpl === null ? null : (int) $cpl,
            targetRoas: $roas === null ? null : (float) $roas,
            minSpendMinor: $min === null ? null : (int) $min,
            maxDailyBudgetMinor: $maxBudget === null ? null : (int) $maxBudget,
            maxBudgetChangePct: $change === null ? self::DEFAULT_MAX_CHANGE_PCT : (int) $change,
            revenueAuthority: RevenueAuthority::tryFrom((string) $authority) ?? RevenueAuthority::PlatformReported,
            forbiddenTerms: is_array($terms) ? array_values(array_map('strval', $terms)) : [],
            importantDates: is_array($dates) ? array_values($dates) : [],
            sources: $sources,
        );
    }
}
