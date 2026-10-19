<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\DTO\Finding;
use App\Application\Agents\Tool\ToolGateway;

/**
 * The Campaign agent: turns the Analyst's findings into proposals — and only proposals.
 *
 * It calls the same tools anybody else would, so the hard limits (change size, ceiling, duplicates, cooldown) apply
 * to it exactly as to an outside AI client. A severe miss proposes a pause; a milder one proposes taking the daily
 * budget down by the smaller of 15% and the project's own change limit. Anything the gateway refuses is counted and
 * named in the run, not retried by another route.
 */
final readonly class CampaignAgent
{
    /** At or past this multiple of the target, the proposal is to pause rather than trim. */
    private const float PAUSE_AT = 2.0;

    private const int TRIM_PCT = 15;

    /**
     * @param  list<Finding>  $findings
     * @return array{proposed: int, tasks: int, refused: array<string, int>, by_tool: array<string, int>, pausing: list<string>}
     */
    public function act(array $findings, ToolGateway $tools, string $agent, int $limit = 8): array
    {
        usort($findings, static fn (Finding $a, Finding $b): int => $b->severity <=> $a->severity);

        $proposed = 0;
        $tasks = 0;
        $refused = [];
        $byTool = [];
        $pausing = [];

        foreach (array_slice($findings, 0, $limit) as $finding) {
            $reason = $this->reason($finding);

            if ($finding->severity >= self::PAUSE_AT) {
                $outcome = $tools->call('propose_pause_campaign', ['campaign_id' => $finding->campaignId, 'reason' => $reason], 'agent', $agent);
                $tool = 'propose_pause_campaign';
            } elseif ($finding->budgetMinor !== null && $finding->budgetMinor > 0) {
                $rules = $tools->call('get_operating_rules', ['project_id' => (string) $finding->projectId], 'agent', $agent);
                $limitPct = (int) ($rules['data']['rules']['max_budget_change_pct'] ?? 20);
                $pct = min(self::TRIM_PCT, $limitPct);
                $outcome = $tools->call('propose_budget_change', [
                    'campaign_id' => $finding->campaignId,
                    'daily_budget_minor' => (int) round($finding->budgetMinor * (100 - $pct) / 100),
                    'reason' => $reason,
                ], 'agent', $agent);
                $tool = 'propose_budget_change';
            } else {
                $refused['no_daily_budget'] = ($refused['no_daily_budget'] ?? 0) + 1;

                continue;
            }

            if ($outcome['ok']) {
                if ($tool === 'propose_pause_campaign') {
                    $pausing[] = $finding->campaignId;
                }

                $proposed++;
                $byTool[$tool] = ($byTool[$tool] ?? 0) + 1;
            } else {
                $code = $outcome['error']['code'] ?? 'failed';
                $refused[$code] = ($refused[$code] ?? 0) + 1;

                /*
                 * A platform whose write has not been read in its own documentation cannot be asked to change, so the
                 * finding still reaches a person — as a task, which is internal and undoable — rather than vanishing.
                 */
                if ($code === 'provider_not_documented') {
                    $task = $tools->call('create_task', [
                        'title' => sprintf('Review %s: %s', $finding->campaignName, $reason),
                        'project_id' => (string) $finding->projectId,
                    ], 'agent', $agent);

                    if ($task['ok']) {
                        $tasks++;
                    }
                }
            }
        }

        return ['proposed' => $proposed, 'tasks' => $tasks, 'refused' => $refused, 'by_tool' => $byTool, 'pausing' => $pausing];
    }

    private function reason(Finding $f): string
    {
        $e = $f->evidence;

        return $f->code === 'cost_over_target'
            ? sprintf('Cost per result is %.1f× the %s target.', $f->severity, (string) ($e['target_source'] ?? 'project'))
            : sprintf('Return is %.2f against a target of %.2f.', (float) ($e['roas'] ?? 0), (float) ($e['target_roas'] ?? 0));
    }
}
