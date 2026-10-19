<?php

declare(strict_types=1);

namespace App\Application\Agents\Agent;

use App\Application\Agents\DTO\AgentFinding;
use App\Application\Agents\Tool\ToolGateway;

/**
 * The Project-operations agent: one card per project that has piled up, instead of the same project appearing six
 * times down the Today list.
 *
 * It reads the queue every other source writes — never the agents' own cards, so it cannot count itself — groups it
 * by project and names the three most pressing reasons. A project manager reads one card and knows where to start.
 */
final readonly class ProjectOperationsAgent
{
    private const int PILE = 3;

    /** @return array{looked_at: int, findings: list<AgentFinding>} */
    public function act(ToolGateway $tools, string $agent): array
    {
        $read = $tools->call('get_attention_items', ['limit' => 50], 'agent', $agent);
        $items = $read['ok'] ? ($read['data']['items'] ?? []) : [];
        $byProject = [];

        foreach ($items as $item) {
            if ($item['stream'] === 'agents' || $item['project_id'] === null) {
                continue;
            }

            $byProject[(string) $item['project_id']][] = $item;
        }

        $findings = [];

        foreach ($byProject as $projectId => $list) {
            $critical = count(array_filter($list, static fn (array $i): bool => $i['severity'] === 'critical'));

            if (count($list) < self::PILE && $critical === 0) {
                continue;
            }

            $first = $list[0];
            $findings[] = new AgentFinding(
                code: 'PROJECT_NEEDS_ACTION',
                subjectKind: 'Project',
                subjectId: $projectId,
                subjectLabel: (string) ($first['project'] ?? ''),
                clientId: null,
                projectId: $projectId,
                severity: $critical > 0 ? 'critical' : 'needs_attention',
                message: sprintf('%s has %d things waiting; start with: %s', (string) ($first['project'] ?? ''), count($list), (string) $first['message']),
                params: ['count' => count($list)],
                evidence: [
                    'critical' => $critical,
                    'top' => array_map(static fn (array $i): array => [
                        'stream' => $i['stream'],
                        'entity' => $i['entity'],
                        'message' => $i['message'],
                        'reason_code' => $i['reason_code'],
                        'reason_params' => $i['reason_params'],
                    ], array_slice($list, 0, 3)),
                ],
            );
        }

        return ['looked_at' => count($items), 'findings' => $findings];
    }
}
