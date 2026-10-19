<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\DTO\AgentFinding;
use App\Application\Agents\Tool\ToolGateway;

/**
 * The Reports agent: prepares each active client's weekly brief from the same figures the screens show.
 *
 * Real data only. Spend and results are kept per currency and never added across; revenue appears only where the
 * platform reported a return on the campaign; the brief states its window, how many days were reported, which
 * platforms it drew on, and what is waiting on a decision. A client with no spend gets no brief — an empty report
 * dressed as a summary is worse than none. The brief is for a person to review and send; the agent sends nothing.
 */
final readonly class ReportsAgent
{
    private const int CLIENTS = 60;

    /** @return array{looked_at: int, findings: list<AgentFinding>} */
    public function act(ToolGateway $tools, string $agent): array
    {
        $listed = $tools->call('list_clients', [], 'agent', $agent);
        $clients = $listed['ok'] ? array_slice($listed['data']['clients'] ?? [], 0, self::CLIENTS) : [];
        $findings = [];

        foreach ($clients as $client) {
            $read = $tools->call('list_campaigns', ['client_id' => $client['id'], 'limit' => 50], 'agent', $agent);

            if (! $read['ok'] || ($read['data']['campaigns'] ?? []) === []) {
                continue;
            }

            $brief = $this->brief($read['data']['campaigns'], (int) $read['data']['matched']);

            if ($brief['totals'] === []) {
                continue;
            }

            $findings[] = new AgentFinding(
                code: 'REPORT_READY',
                subjectKind: 'Client',
                subjectId: (string) $client['id'],
                subjectLabel: (string) $client['name'],
                clientId: (string) $client['id'],
                projectId: null,
                severity: 'watch',
                message: sprintf('The weekly brief for %s is ready to review.', $client['name']),
                params: ['title' => (string) $client['name']],
                evidence: $brief,
            );
        }

        return ['looked_at' => count($clients), 'findings' => $findings];
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return array<string, mixed>
     */
    private function brief(array $campaigns, int $matched): array
    {
        $totals = [];
        $health = [];
        $providers = [];
        $reportedDays = 0;

        foreach ($campaigns as $c) {
            $health[(string) $c['health']] = ($health[(string) $c['health']] ?? 0) + 1;
            $providers[(string) $c['provider']] = true;
            $reportedDays = max($reportedDays, (int) $c['reported_days']);

            if ($c['currency'] === null || $c['spend_minor'] === null || $c['spend_minor'] <= 0) {
                continue;
            }

            $t = $totals[$c['currency']] ?? ['spend_minor' => 0, 'results' => 0.0, 'revenue_minor' => null];
            $t['spend_minor'] += (int) $c['spend_minor'];
            $t['results'] += (float) ($c['results'] ?? 0);

            if ($c['roas'] !== null) {
                $t['revenue_minor'] = ($t['revenue_minor'] ?? 0) + (int) round((float) $c['roas'] * (int) $c['spend_minor']);
            }

            $totals[$c['currency']] = $t;
        }

        foreach ($totals as $currency => $t) {
            $totals[$currency]['roas'] = $t['revenue_minor'] === null || $t['spend_minor'] === 0 ? null : round($t['revenue_minor'] / $t['spend_minor'], 2);
        }

        $spending = array_filter($campaigns, static fn (array $c): bool => ($c['spend_minor'] ?? 0) > 0);
        usort($spending, static fn (array $a, array $b): int => (float) ($b['roas'] ?? -1) <=> (float) ($a['roas'] ?? -1));

        return [
            'campaigns' => $matched,
            'reported_days' => $reportedDays,
            'sources' => array_keys($providers),
            'totals' => $totals,
            'health' => $health,
            'best' => $spending === [] ? null : $spending[0]['name'],
            'worst' => count($spending) < 2 ? null : $spending[count($spending) - 1]['name'],
        ];
    }
}
