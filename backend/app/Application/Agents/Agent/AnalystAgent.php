<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\DTO\Finding;
use App\Application\Agents\Tool\ToolGateway;

/**
 * The Analyst: reads, compares to the project's own targets, and reports. It never changes anything.
 *
 * Every comparison is arithmetic done here, in code, against a target somebody set — the Analyst does not decide what
 * good is. Where a project has no target the campaign is not judged, and the run says how many were left alone for
 * that reason: silence about a missing target would look like a campaign doing well.
 */
final readonly class AnalystAgent
{
    /** Past this multiple of the target cost, or under this share of the target return, a campaign is a finding. */
    private const float COST_FACTOR = 1.5;

    private const float RETURN_FACTOR = 0.7;

    /** Days of reported figures before a verdict means anything. */
    private const int MIN_DAYS = 5;

    private const int PAGES = 8;

    /**
     * @return array{findings: list<Finding>, looked_at: int, without_target: int}
     */
    public function analyse(ToolGateway $tools, string $agent): array
    {
        /* Worst health first, a page at a time, up to four hundred campaigns — more than a morning's review can act on. */
        $campaigns = [];

        for ($page = 0; $page < self::PAGES; $page++) {
            $list = $tools->call('list_campaigns', ['limit' => 50, 'offset' => $page * 50], 'agent', $agent);
            $rows = $list['ok'] ? ($list['data']['campaigns'] ?? []) : [];
            $campaigns = [...$campaigns, ...$rows];

            if (count($rows) < 50) {
                break;
            }
        }

        $rulesByProject = [];
        $findings = [];
        $withoutTarget = 0;

        foreach ($campaigns as $c) {
            if (($c['status'] ?? '') !== 'active' || ($c['project_id'] ?? null) === null) {
                continue;
            }

            if ((int) ($c['reported_days'] ?? 0) < self::MIN_DAYS) {
                continue;
            }

            $project = (string) $c['project_id'];

            if (! array_key_exists($project, $rulesByProject)) {
                $r = $tools->call('get_operating_rules', ['project_id' => $project], 'agent', $agent);
                $rulesByProject[$project] = $r['ok'] ? ($r['data']['rules'] ?? null) : null;
            }

            $rules = $rulesByProject[$project];

            if ($rules === null) {
                continue;
            }

            $minSpend = $rules['min_spend_minor'] ?? null;

            if ($minSpend !== null && (int) ($c['spend_minor'] ?? 0) < (int) $minSpend) {
                continue;
            }

            $finding = $this->judge($c, $rules);

            if ($finding === 'no_target') {
                $withoutTarget++;

                continue;
            }

            if ($finding instanceof Finding) {
                $findings[] = $finding;
            }
        }

        return ['findings' => $findings, 'looked_at' => count($campaigns), 'without_target' => $withoutTarget];
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $rules
     */
    private function judge(array $c, array $rules): Finding|string|null
    {
        $cost = $c['cost_per_result_minor'] ?? null;
        $roas = $c['roas'] ?? null;
        $targetCpa = $rules['target_cpa_minor'] ?? ($rules['target_cpl_minor'] ?? null);
        $targetRoas = $rules['target_roas'] ?? null;

        if ($targetCpa === null && $targetRoas === null) {
            return 'no_target';
        }

        $base = [
            'campaign' => $c['name'],
            'currency' => $c['currency'] ?? null,
            'spend_minor' => $c['spend_minor'] ?? null,
            'reported_days' => $c['reported_days'] ?? null,
            'revenue_authority' => $rules['revenue_authority'] ?? null,
        ];

        if ($targetCpa !== null && $cost !== null && $cost > (float) $targetCpa * self::COST_FACTOR) {
            return new Finding(
                code: 'cost_over_target',
                campaignId: (string) $c['id'],
                campaignName: (string) $c['name'],
                projectId: (string) $c['project_id'],
                clientId: $c['client_id'] ?? null,
                severity: $cost / (float) $targetCpa,
                budgetMinor: $c['budget_minor'] ?? null,
                evidence: $base + [
                    'cost_per_result_minor' => $cost,
                    'target_cpa_minor' => $targetCpa,
                    'target_source' => $rules['sources']['target_cpa_minor'] ?? 'agency_default',
                ],
            );
        }

        if ($targetRoas !== null && $roas !== null && $roas < (float) $targetRoas * self::RETURN_FACTOR) {
            return new Finding(
                code: 'return_under_target',
                campaignId: (string) $c['id'],
                campaignName: (string) $c['name'],
                projectId: (string) $c['project_id'],
                clientId: $c['client_id'] ?? null,
                severity: (float) $targetRoas / max((float) $roas, 0.01),
                budgetMinor: $c['budget_minor'] ?? null,
                evidence: $base + [
                    'roas' => $roas,
                    'target_roas' => $targetRoas,
                    'target_source' => $rules['sources']['target_roas'] ?? 'agency_default',
                ],
            );
        }

        return null;
    }
}
