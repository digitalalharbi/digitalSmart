<?php

declare(strict_types=1);

namespace App\Infrastructure\Agents;

use App\Application\Agents\Contract\FindingLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentFindingLog implements FindingLog
{
    public function observe(string $workspaceId, string $agent, array $findings): array
    {
        return DB::transaction(static function () use ($workspaceId, $agent, $findings): array {
            $now = now();
            $seen = [];
            $ids = [];
            $opened = 0;
            $kept = 0;

            foreach ($findings as $f) {
                $key = $f->code.'|'.$f->subjectId;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $values = [
                    'subject_kind' => $f->subjectKind,
                    'subject_label' => mb_substr($f->subjectLabel, 0, 250),
                    'client_id' => $f->clientId,
                    'project_id' => $f->projectId,
                    'severity' => $f->severity,
                    'message' => $f->message,
                    'params' => json_encode((object) $f->params, JSON_THROW_ON_ERROR),
                    'evidence' => json_encode((object) $f->evidence, JSON_THROW_ON_ERROR),
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ];

                $existing = DB::table('agent_findings')
                    ->where(['workspace_id' => $workspaceId, 'agent' => $agent, 'code' => $f->code, 'subject_id' => $f->subjectId])
                    ->whereNull('resolved_at')
                    ->value('id');

                if (is_string($existing)) {
                    DB::table('agent_findings')->where('id', $existing)->update($f->taskId === null ? $values : [...$values, 'task_id' => $f->taskId]);
                    $ids[] = $existing;
                    $kept++;

                    continue;
                }

                $id = (string) Str::uuid();
                $ids[] = $id;
                DB::table('agent_findings')->insert([
                    ...$values,
                    'id' => $id,
                    'workspace_id' => $workspaceId,
                    'agent' => $agent,
                    'code' => $f->code,
                    'subject_id' => $f->subjectId,
                    'task_id' => $f->taskId,
                    'first_seen_at' => $now,
                    'created_at' => $now,
                ]);
                $opened++;
            }

            $stale = DB::table('agent_findings')
                ->where(['workspace_id' => $workspaceId, 'agent' => $agent])
                ->whereNull('resolved_at')
                ->whereNotIn('id', $ids)
                ->update(['resolved_at' => $now, 'updated_at' => $now]);

            return ['opened' => $opened, 'kept' => $kept, 'resolved' => $stale];
        });
    }

    public function open(string $workspaceId, ?array $visibleProjectIds, int $limit = 60): array
    {
        $query = DB::table('agent_findings as f')
            ->leftJoin('clients as c', 'c.id', '=', 'f.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'f.project_id')
            ->where('f.workspace_id', $workspaceId)
            ->whereNull('f.resolved_at');

        if ($visibleProjectIds !== null) {
            $query->whereIn('f.project_id', $visibleProjectIds);
        }

        $rows = $query
            ->orderByRaw("case f.severity when 'critical' then 0 when 'needs_attention' then 1 else 2 end")
            ->orderByDesc('f.last_seen_at')
            ->limit($limit)
            ->get(['f.*', 'c.name as client_name', 'p.name as project_name']);

        return array_values(array_map(static function (object $r): array {
            /** @var array<string, int|string|float> $params */
            $params = json_decode((string) $r->params, true, 8, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed> $evidence */
            $evidence = json_decode((string) $r->evidence, true, 16, JSON_THROW_ON_ERROR);

            return [
                'id' => (string) $r->id,
                'agent' => (string) $r->agent,
                'code' => (string) $r->code,
                'subject_kind' => (string) $r->subject_kind,
                'subject_id' => (string) $r->subject_id,
                'subject_label' => (string) $r->subject_label,
                'client_id' => $r->client_id === null ? null : (string) $r->client_id,
                'client_name' => $r->client_name === null ? null : (string) $r->client_name,
                'project_id' => $r->project_id === null ? null : (string) $r->project_id,
                'project_name' => $r->project_name === null ? null : (string) $r->project_name,
                'severity' => (string) $r->severity,
                'message' => (string) $r->message,
                'params' => $params,
                'evidence' => $evidence,
                'task_id' => $r->task_id === null ? null : (string) $r->task_id,
                'first_seen_at' => (string) $r->first_seen_at,
            ];
        }, $rows->all()));
    }

    public function isOpen(string $workspaceId, string $agent, string $code, string $subjectId): bool
    {
        return DB::table('agent_findings')
            ->where(['workspace_id' => $workspaceId, 'agent' => $agent, 'code' => $code, 'subject_id' => $subjectId])
            ->whereNull('resolved_at')
            ->exists();
    }
}
