<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\DTO\AgentFinding;
use App\Application\Agents\Tool\ToolGateway;

/**
 * The Leads agent: reads each client's leads in their three separate counts — lead, qualified, sale — and says when
 * volume falls, when quality is poor, and which source is sending leads that never qualify.
 *
 * Two fortnights are compared (the last fourteen days and the fourteen before, the latter derived from a 28-day read
 * so the windows never overlap). Nothing is judged on fewer than ten leads: a small number moving is noise, and a card
 * built on noise teaches people to ignore cards.
 */
final readonly class LeadsAgent
{
    private const int MIN_LEADS = 10;

    private const float DECLINE = 0.3;

    /** Percent of reached leads that qualified. */
    private const float POOR_QUALITY = 20.0;

    private const int CLIENTS = 60;

    /** @return array{looked_at: int, findings: list<AgentFinding>} */
    public function act(ToolGateway $tools, string $agent): array
    {
        $listed = $tools->call('list_clients', [], 'agent', $agent);
        $clients = $listed['ok'] ? array_slice($listed['data']['clients'] ?? [], 0, self::CLIENTS) : [];
        $findings = [];
        $looked = 0;

        foreach ($clients as $client) {
            $now = $tools->call('get_lead_quality', ['client_id' => $client['id'], 'days' => 14], 'agent', $agent);
            $month = $tools->call('get_lead_quality', ['client_id' => $client['id'], 'days' => 28], 'agent', $agent);

            $current = $now['data'] ?? null;
            $wider = $month['data'] ?? null;

            if ($current === null || $wider === null) {
                continue;
            }

            $looked++;
            $before = (int) $wider['leads'] - (int) $current['leads'];
            $counts = ['leads' => $current['leads'], 'reached' => $current['reached'], 'qualified' => $current['qualified'], 'won' => $current['won'], 'leads_before' => $before];

            if ($before >= self::MIN_LEADS && $current['leads'] < $before * (1 - self::DECLINE)) {
                $drop = (int) round((1 - $current['leads'] / $before) * 100);
                $findings[] = new AgentFinding(
                    code: 'LEADS_DECLINE',
                    subjectKind: 'Client',
                    subjectId: (string) $client['id'],
                    subjectLabel: (string) $client['name'],
                    clientId: (string) $client['id'],
                    projectId: null,
                    severity: $drop >= 50 ? 'needs_attention' : 'watch',
                    message: sprintf('Leads for %s fell %d%% against the fortnight before (%d → %d).', $client['name'], $drop, $before, $current['leads']),
                    params: ['drop' => $drop],
                    evidence: $counts,
                );
            }

            $rate = $current['qualification_rate'];

            if ((int) $current['reached'] >= self::MIN_LEADS && $rate !== null && $rate < self::POOR_QUALITY) {
                $wasted = array_values(array_filter(
                    $current['by_campaign'],
                    static fn (array $c): bool => $c['leads'] >= 5 && $c['qualified'] === 0 && $c['won'] === 0,
                ));

                $findings[] = new AgentFinding(
                    code: 'LEADS_LOW_QUALITY',
                    subjectKind: 'Client',
                    subjectId: (string) $client['id'],
                    subjectLabel: (string) $client['name'],
                    clientId: (string) $client['id'],
                    projectId: null,
                    severity: 'needs_attention',
                    message: sprintf('Only %d%% of the leads %s reached qualified in the last fortnight.', (int) round($rate), $client['name']),
                    params: ['rate' => (int) round($rate)],
                    evidence: [...$counts, 'unqualified_campaigns' => array_map(static fn (array $c): array => ['campaign' => $c['campaign'], 'leads' => $c['leads']], array_slice($wasted, 0, 5))],
                );
            }
        }

        return ['looked_at' => $looked, 'findings' => $findings];
    }
}
