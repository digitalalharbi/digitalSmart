<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\DTO\AgentFinding;
use App\Application\Agents\Tool\ToolGateway;

/**
 * The Creative agent: finds ads the audience has stopped responding to, and asks for a replacement.
 *
 * Fatigue is stated, not guessed: click-through this week at least a quarter below the three weeks before, on an ad
 * seen at least a thousand times this week. The brief it writes names the drop, the frequency and the strongest
 * sibling in the same campaign — the winner to learn from. It opens a task for the creative team; it never pauses an
 * ad, and an ad whose campaign is already proposed for pausing gets no task (replacing artwork on a campaign about to
 * stop is wasted work — the pause wins).
 */
final readonly class CreativeAgent
{
    public const float TIRING = -0.25;

    private const int MIN_IMPRESSIONS = 1000;

    private const int TASKS_PER_RUN = 6;

    private const int PAGES = 3;

    /**
     * @param  list<string>  $pausing  Campaigns with a pause proposal raised this run.
     * @return array{looked_at: int, findings: list<AgentFinding>, tasks: int, superseded: int, winners: int}
     */
    public function act(ToolGateway $tools, string $agent, array $pausing = []): array
    {
        $ads = [];

        for ($page = 0; $page < self::PAGES; $page++) {
            $read = $tools->call('list_ad_signals', ['limit' => 100, 'offset' => $page * 100, 'min_impressions' => self::MIN_IMPRESSIONS], 'agent', $agent);

            if (! $read['ok']) {
                break;
            }

            $ads = [...$ads, ...($read['data']['ads'] ?? [])];

            if (($page + 1) * 100 >= (int) ($read['data']['matched'] ?? 0)) {
                break;
            }
        }

        $best = [];

        foreach ($ads as $ad) {
            $campaign = (string) $ad['campaign_id'];

            if ($ad['ctr_now'] !== null && (! isset($best[$campaign]) || $ad['ctr_now'] > $best[$campaign]['ctr_now'])) {
                $best[$campaign] = $ad;
            }
        }

        $tiring = array_values(array_filter($ads, static fn (array $a): bool => $a['ctr_change'] !== null && $a['ctr_change'] <= self::TIRING));
        usort($tiring, static fn (array $a, array $b): int => $a['ctr_change'] <=> $b['ctr_change']);

        $findings = [];
        $tasks = 0;
        $superseded = 0;

        foreach (array_slice($tiring, 0, 30) as $ad) {
            $drop = (int) round(abs((float) $ad['ctr_change']) * 100);
            $winner = $best[(string) $ad['campaign_id']] ?? null;
            $winner = $winner !== null && $winner['id'] !== $ad['id'] ? $winner : null;
            $taskId = null;

            if (in_array($ad['campaign_id'], $pausing, true)) {
                $superseded++;
            } elseif ($tasks < self::TASKS_PER_RUN) {
                $made = $tools->call('create_task', [
                    'title' => sprintf('Replace tiring creative: %s — %s', $ad['name'], $ad['campaign']),
                    'description' => $this->brief($ad, $drop, $winner),
                    ...($ad['project_id'] === null ? [] : ['project_id' => (string) $ad['project_id']]),
                ], 'agent', $agent);

                if ($made['ok'] && isset($made['data']['task']['id'])) {
                    $tasks++;
                    $taskId = (string) $made['data']['task']['id'];
                }
            }

            $findings[] = new AgentFinding(
                code: 'CREATIVE_FATIGUE',
                subjectKind: 'Ad',
                subjectId: (string) $ad['id'],
                subjectLabel: sprintf('%s — %s', $ad['name'], $ad['campaign']),
                clientId: $ad['client_id'],
                projectId: $ad['project_id'],
                severity: $drop >= 40 ? 'needs_attention' : 'watch',
                message: sprintf('Click-through on %s fell %d%% week on week; a replacement has been asked for.', $ad['name'], $drop),
                params: ['drop' => $drop],
                evidence: [
                    'ctr_now' => $ad['ctr_now'],
                    'ctr_before' => $ad['ctr_before'],
                    'frequency' => $ad['frequency'],
                    'impressions_now' => $ad['impressions_now'],
                    'thumbnail_url' => $ad['creative']['thumbnail_url'] ?? null,
                    'campaign_id' => $ad['campaign_id'],
                    'winner' => $winner === null ? null : ['name' => $winner['name'], 'ctr_now' => $winner['ctr_now'], 'thumbnail_url' => $winner['creative']['thumbnail_url'] ?? null],
                    'superseded_by_pause' => in_array($ad['campaign_id'], $pausing, true),
                ],
                taskId: $taskId,
            );
        }

        return ['looked_at' => count($ads), 'findings' => $findings, 'tasks' => $tasks, 'superseded' => $superseded, 'winners' => count($best)];
    }

    /**
     * @param  array<string, mixed>  $ad
     * @param  array<string, mixed>|null  $winner
     */
    private function brief(array $ad, int $drop, ?array $winner): string
    {
        $lines = [
            sprintf('Seen: click-through %.2f%% this week against %.2f%% before (−%d%%).', (float) $ad['ctr_now'] * 100, (float) $ad['ctr_before'] * 100, $drop),
        ];

        if ($ad['frequency'] !== null) {
            $lines[] = sprintf('The same people saw it %.1f times on average this week.', (float) $ad['frequency']);
        }

        if ($winner !== null) {
            $lines[] = sprintf('Strongest ad in the same campaign: %s at %.2f%% — start from what it does.', $winner['name'], (float) $winner['ctr_now'] * 100);
        }

        $lines[] = 'Asked for: a new version with the same offer and a different opening (first second / headline) and visual.';
        $lines[] = 'Keep the platform\'s sizes and safe zones; the old ad stays live until the new one is approved.';

        return implode("\n", $lines);
    }
}
