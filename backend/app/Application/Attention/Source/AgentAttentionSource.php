<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Agents\Contract\FindingLog;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use DateTimeImmutable;

/**
 * What the Creative, Leads, Reports and Project-operations agents still see.
 *
 * Read from stored findings that close themselves when the agent stops seeing them, so a card on Today is as current
 * as the agent's last run and never older. Scoped to the reader's projects like every other stream.
 */
final readonly class AgentAttentionSource implements AttentionSource
{
    public function __construct(
        private FindingLog $findings,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Agents;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $rows = $this->findings->open($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), 200);

        /*
         * Weekly briefs are one card, not one per client: fifty "ready to review" lines would bury the three things
         * that are actually wrong. The briefs themselves are read on the Reports page.
         */
        $briefs = array_values(array_filter($rows, static fn (array $f): bool => $f['code'] === 'REPORT_READY'));
        $rows = array_values(array_filter($rows, static fn (array $f): bool => $f['code'] !== 'REPORT_READY'));
        /*
         * Watch-level findings of one kind become one card ("9 more ads are tiring"): each is worth knowing, none is
         * worth a slot of its own above a decision someone is waiting on. The individual findings stay on the
         * agents' cards and pages.
         */
        $watch = [];
        $items = [];

        foreach (array_slice($rows, 0, 120) as $f) {
            if ($f['severity'] === 'watch') {
                $watch[$f['code']][] = $f;

                continue;
            }

            $items[] = self::item($f);
        }

        foreach ($watch as $code => $group) {
            $items[] = count($group) === 1 ? self::item($group[0]) : new AttentionItem(
                id: 'agent:watch:'.$code,
                stream: AttentionStream::Agents,
                severity: AttentionSeverity::Watch,
                message: sprintf('%d more of these: %s', count($group), $group[0]['message']),
                entityKind: $group[0]['subject_kind'],
                entityLabel: $group[0]['subject_label'],
                owner: false,
                since: new DateTimeImmutable(min(array_column($group, 'first_seen_at'))),
                clientId: null,
                clientName: null,
                projectId: null,
                projectName: null,
                reasonCode: $code.'_MORE',
                reasonParams: ['count' => count($group)],
            );
        }

        if ($briefs !== []) {
            $items[] = new AttentionItem(
                id: 'agent:briefs',
                stream: AttentionStream::Agents,
                severity: AttentionSeverity::Watch,
                message: sprintf('Weekly briefs are ready to review for %d clients.', count($briefs)),
                entityKind: 'Report',
                entityLabel: 'Weekly briefs',
                owner: false,
                since: new DateTimeImmutable(min(array_column($briefs, 'first_seen_at'))),
                clientId: null,
                clientName: null,
                projectId: null,
                projectName: null,
                reasonCode: 'REPORTS_READY',
                reasonParams: ['count' => count($briefs)],
            );
        }

        return $items;
    }

    /** @param array{id: string, code: string, subject_kind: string, subject_label: string, client_id: string|null, client_name: string|null, project_id: string|null, project_name: string|null, severity: string, message: string, params: array<string, int|string|float>, first_seen_at: string} $f */
    private static function item(array $f): AttentionItem
    {
        return new AttentionItem(
            id: 'agent:'.$f['id'],
            stream: AttentionStream::Agents,
            severity: AttentionSeverity::from($f['severity']),
            message: $f['message'],
            entityKind: $f['subject_kind'],
            entityLabel: $f['subject_label'],
            owner: false,
            since: new DateTimeImmutable($f['first_seen_at']),
            clientId: $f['client_id'],
            clientName: $f['client_name'],
            projectId: $f['project_id'],
            projectName: $f['project_name'],
            reasonCode: $f['code'],
            reasonParams: $f['params'],
        );
    }
}
