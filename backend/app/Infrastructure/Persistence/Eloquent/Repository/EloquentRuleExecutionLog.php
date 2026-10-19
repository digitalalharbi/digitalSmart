<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Automation\Contract\RuleExecutionLog;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class EloquentRuleExecutionLog implements RuleExecutionLog
{
    public function __construct(private IdentifierGenerator $ids, private Clock $clock) {}

    public function record(string $workspaceId, array $entry): void
    {
        DB::table('automation_rule_executions')->insert([
            ...$entry,
            'id' => $this->ids->generate(),
            'workspace_id' => $workspaceId,
            'object_label' => mb_substr($entry['object_label'], 0, 250),
            'before' => json_encode((object) $entry['before'], JSON_THROW_ON_ERROR),
            'after' => json_encode((object) $entry['after'], JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->now(),
        ]);
    }

    public function lastActedAt(string $ruleId, string $campaignId, DateTimeImmutable $since): ?DateTimeImmutable
    {
        $at = DB::table('automation_rule_executions')
            ->where(['rule_id' => $ruleId, 'campaign_id' => $campaignId])
            ->where('created_at', '>=', $since)
            ->whereIn('result', ['completed', 'waiting_approval'])
            ->max('created_at');

        return is_string($at) ? new DateTimeImmutable($at) : null;
    }

    public function conflicting(string $ruleId, string $campaignId, array $opposite, DateTimeImmutable $since): ?string
    {
        if ($opposite === []) {
            return null;
        }

        $other = DB::table('automation_rule_executions as e')
            ->join('automation_rules as r', 'r.id', '=', 'e.rule_id')
            ->where('e.campaign_id', $campaignId)
            ->where('e.rule_id', '!=', $ruleId)
            ->whereIn('e.action', $opposite)
            ->whereIn('e.result', ['completed', 'waiting_approval'])
            ->where('e.created_at', '>=', $since)
            ->orderByDesc('e.created_at')
            ->value('r.name');

        return is_string($other) ? $other : null;
    }

    public function history(string $workspaceId, ?array $visibleProjectIds, array $filters, DateTimeImmutable $from, int $limit): array
    {
        $query = $this->scoped($workspaceId, $visibleProjectIds)
            ->leftJoin('automation_rules as r', 'r.id', '=', 'e.rule_id')
            ->leftJoin('clients as c', 'c.id', '=', 'e.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'e.project_id')
            ->where('e.created_at', '>=', $from);

        foreach (['rule_id', 'result', 'provider', 'client_id', 'project_id'] as $key) {
            if (($filters[$key] ?? null) !== null) {
                $query->where('e.'.$key, $filters[$key]);
            }
        }

        return array_values(array_map(static fn (object $r): array => [
            'id' => (string) $r->id,
            'at' => (string) $r->created_at,
            'rule' => ['id' => (string) $r->rule_id, 'name' => (string) ($r->rule_name ?? '')],
            'client' => $r->client_id === null ? null : ['id' => (string) $r->client_id, 'name' => (string) $r->client_name],
            'project' => $r->project_id === null ? null : ['id' => (string) $r->project_id, 'name' => (string) $r->project_name],
            'provider' => $r->provider,
            'object' => ['level' => (string) $r->object_level, 'label' => (string) $r->object_label, 'campaign_id' => $r->campaign_id],
            'action' => (string) $r->action,
            'before' => json_decode((string) $r->before, true),
            'after' => json_decode((string) $r->after, true),
            'result' => (string) $r->result,
            'reason_code' => $r->reason_code,
            'approval_id' => $r->approval_id,
        ], $query->orderByDesc('e.created_at')->limit($limit)
            ->get(['e.*', 'r.name as rule_name', 'c.name as client_name', 'p.name as project_name'])->all()));
    }

    public function counts(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $length = $to->getTimestamp() - $from->getTimestamp();
        $before = $from->setTimestamp($from->getTimestamp() - $length);
        $out = [];

        foreach ($this->scoped($workspaceId, $visibleProjectIds)
            ->where('e.created_at', '>=', $before)
            ->where('e.created_at', '<=', $to)
            ->selectRaw("e.action, e.result, case when e.created_at >= ? then 'now' else 'before' end as span, count(*) as n", [$from])
            ->groupBy('e.action', 'e.result', 'span')
            ->get() as $row) {
            $span = $row->span === 'now' ? 'now' : 'before';

            foreach (['all', 'action:'.$row->action, 'result:'.$row->result] as $key) {
                $counts = $out[$key] ?? ['now' => 0, 'before' => 0];
                $counts[$span] += (int) $row->n;
                $out[$key] = $counts;
            }
        }

        return $out;
    }

    public function rulesActedSince(string $workspaceId, DateTimeImmutable $since): array
    {
        return array_values(array_map('strval', DB::table('automation_rule_executions')
            ->where('workspace_id', $workspaceId)->where('created_at', '>=', $since)
            ->whereIn('result', ['completed', 'waiting_approval'])
            ->distinct()->pluck('rule_id')->all()));
    }

    public function changeLog(string $workspaceId, ?array $ruleIds, DateTimeImmutable $from, int $limit): array
    {
        $query = DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')
            ->leftJoin('automation_rules as r', 'r.id', '=', 'a.auditable_id')
            ->where('a.workspace_id', $workspaceId)
            ->where('a.auditable_type', 'automation_rule')
            ->where('a.created_at', '>=', $from);

        if ($ruleIds !== null) {
            $ruleIds === [] ? $query->whereRaw('false') : $query->whereIn('a.auditable_id', $ruleIds);
        }

        return array_values(array_map(static function (object $r): array {
            /** @var array<string, mixed> $changes */
            $changes = json_decode((string) ($r->changes ?? '{}'), true) ?: [];
            $kinds = $changes['kinds'] ?? null;

            return [
                'at' => (string) $r->created_at,
                'who' => $r->who === null ? ($r->actor_label === null ? null : (string) $r->actor_label) : (string) $r->who,
                'rule_id' => (string) $r->auditable_id,
                'rule' => $r->rule_name === null ? null : (string) $r->rule_name,
                /* Entries written before kinds existed read as a plain edit or deletion. */
                'kinds' => is_array($kinds) ? array_values(array_map('strval', $kinds)) : [str_contains((string) $r->action, 'deleted') ? 'archived' : 'edited'],
                'before' => is_array($changes['before'] ?? null) ? $changes['before'] : null,
                'after' => is_array($changes['after'] ?? null) ? $changes['after'] : null,
            ];
        }, $query->orderByDesc('a.created_at')->limit($limit)
            ->get(['a.created_at', 'a.auditable_id', 'a.action', 'a.changes', 'a.actor_label', 'u.name as who', 'r.name as rule_name'])->all()));
    }

    /** @param list<string>|null $visibleProjectIds */
    private function scoped(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $query = DB::table('automation_rule_executions as e')->where('e.workspace_id', $workspaceId);

        if ($visibleProjectIds !== null) {
            /* A reader limited to some projects sees those projects' actions; none means none. */
            $visibleProjectIds === [] ? $query->whereRaw('false') : $query->whereIn('e.project_id', $visibleProjectIds);
        }

        return $query;
    }
}
