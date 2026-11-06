<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingLinkHistory;
use App\Application\Tracking\DTO\LinkRevision;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class EloquentTrackingLinkHistory implements TrackingLinkHistory
{
    public function record(string $workspaceId, string $linkId, array $snapshot, ?string $actorId): ?int
    {
        ksort($snapshot);

        return DB::transaction(function () use ($workspaceId, $linkId, $snapshot, $actorId): ?int {
            // The link row is locked so two saves of one link cannot both take the same revision number.
            DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('id', $linkId)->lockForUpdate()->first();

            $latest = DB::table('tracking_link_revisions')
                ->where('workspace_id', $workspaceId)
                ->where('tracking_link_id', $linkId)
                ->orderByDesc('revision')
                ->first();

            $previous = $latest === null ? null : $this->decode($latest->snapshot);
            $changed = [];

            if ($previous !== null) {
                /*
                 * The fields of both sides, not only the new one: a value that was set and is now absent is a change, and a
                 * comparison that walked just the new snapshot would never see it.
                 */
                $fields = array_unique([...array_keys($snapshot), ...array_keys($previous)]);
                sort($fields);

                foreach ($fields as $field) {
                    // Compared as stored: a round trip through JSON must not read as a change.
                    if (json_encode($previous[$field] ?? null) !== json_encode($snapshot[$field] ?? null)) {
                        $changed[] = $field;
                    }
                }

                if ($changed === []) {
                    return null;
                }
            }

            $revision = $latest === null ? 1 : ((int) $latest->revision) + 1;

            DB::table('tracking_link_revisions')->insert([
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspaceId,
                'tracking_link_id' => $linkId,
                'revision' => $revision,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'changed_fields' => json_encode($changed, JSON_THROW_ON_ERROR),
                'changed_by' => $actorId,
                'backfilled' => false,
                'effective_from' => now(),
            ]);

            return $revision;
        });
    }

    public function revisions(string $workspaceId, string $linkId): array
    {
        $rows = DB::table('tracking_link_revisions as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.changed_by')
            ->where('r.workspace_id', $workspaceId)
            ->where('r.tracking_link_id', $linkId)
            ->orderBy('r.revision')
            ->get(['r.revision', 'r.snapshot', 'r.changed_fields', 'r.backfilled', 'r.effective_from', 'u.name as changed_by_name'])
            ->all();

        $out = [];

        foreach ($rows as $index => $row) {
            $from = new DateTimeImmutable((string) $row->effective_from);
            $next = $rows[$index + 1] ?? null;
            $until = $next === null ? null : new DateTimeImmutable((string) $next->effective_from);

            $clicks = DB::table('tracking_sessions')
                ->where('workspace_id', $workspaceId)
                ->where('tracking_link_id', $linkId)
                ->where('traffic_class', 'valid')
                // The first revision also holds anything that arrived before history began.
                ->when($index > 0, static fn ($query) => $query->where('started_at', '>=', $from))
                ->when($until !== null, static fn ($query) => $query->where('started_at', '<', $until))
                ->count();

            $fields = [];

            foreach ($this->decode($row->changed_fields) as $field) {
                if (is_string($field)) {
                    $fields[] = $field;
                }
            }

            $out[] = new LinkRevision(
                revision: (int) $row->revision,
                snapshot: $this->decode($row->snapshot),
                changedFields: $fields,
                changedByName: is_string($row->changed_by_name) ? $row->changed_by_name : null,
                backfilled: (bool) $row->backfilled,
                effectiveFrom: $from,
                effectiveUntil: $until,
                clicks: $clicks,
            );
        }

        return array_reverse($out);
    }

    /** @return array<array-key, mixed> */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) ? $decoded : [];
    }
}
