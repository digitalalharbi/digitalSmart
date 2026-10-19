<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleMode;
use App\Domain\Automation\RuleOperator;
use App\Domain\Automation\RuleSchedule;
use App\Domain\Automation\RuleSeverity;
use App\Domain\Automation\TrustLevel;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

final readonly class EloquentAutomationRuleRepository implements AutomationRuleRepository
{
    public function __construct(private IdentifierGenerator $ids, private Clock $clock) {}

    public function visibleTo(string $workspaceId, ?array $visibleProjectIds, bool $activeOnly = false): array
    {
        $query = $this->base()->where('r.workspace_id', $workspaceId);

        if ($activeOnly) {
            $query->where('r.is_active', true);
        }

        if ($visibleProjectIds !== null) {
            $query->where(static function (Builder $q) use ($visibleProjectIds): void {
                $q->where(static fn (Builder $agency) => $agency->whereNull('r.client_id')->whereNull('r.project_id'));

                if ($visibleProjectIds !== []) {
                    $q->orWhereIn('r.project_id', $visibleProjectIds)
                        ->orWhereIn('r.client_id', DB::table('projects')->select('client_id')->whereIn('id', $visibleProjectIds));
                }
            });
        }

        return array_values(array_map($this->view(...), $query->orderBy('r.name')->get()->all()));
    }

    public function find(string $workspaceId, string $id): ?RuleView
    {
        $row = $this->base()->where('r.workspace_id', $workspaceId)->where('r.id', $id)->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function nameTaken(string $workspaceId, string $name, ?string $exceptId = null): bool
    {
        $query = DB::table('automation_rules')->where(['workspace_id' => $workspaceId, 'name' => $name]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    public function create(string $workspaceId, string $actorId, RuleInput $input): RuleView
    {
        $id = $this->ids->generate();
        $now = $this->clock->now();

        DB::table('automation_rules')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'created_by' => $actorId,
            ...$this->columns($input),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($workspaceId, $id) ?? throw new LogicException('A rule that was just written could not be read back.');
    }

    public function update(string $workspaceId, string $id, RuleInput $input): RuleView
    {
        DB::table('automation_rules')->where(['workspace_id' => $workspaceId, 'id' => $id])
            ->update([...$this->columns($input), 'updated_at' => $this->clock->now()]);

        return $this->find($workspaceId, $id) ?? throw new LogicException('A rule that was just changed could not be read back.');
    }

    public function observe(string $ruleId, array $entities, string $today): array
    {
        $streaks = [];

        foreach ($entities as $entityId => $campaignId) {
            DB::statement(
                'INSERT INTO automation_rule_streaks (rule_id, entity_id, campaign_id, first_seen_on, last_seen_on) VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (rule_id, entity_id) DO UPDATE SET
                   first_seen_on = CASE WHEN automation_rule_streaks.last_seen_on < (?::date - 1) THEN EXCLUDED.first_seen_on ELSE automation_rule_streaks.first_seen_on END,
                   acted_at = CASE WHEN automation_rule_streaks.last_seen_on < (?::date - 1) THEN NULL ELSE automation_rule_streaks.acted_at END,
                   last_seen_on = EXCLUDED.last_seen_on',
                [$ruleId, (string) $entityId, $campaignId, $today, $today, $today, $today],
            );
        }

        /* An entity the rule no longer holds for starts again from nothing next time. */
        $gone = DB::table('automation_rule_streaks')->where('rule_id', $ruleId);
        ($entities === [] ? $gone : $gone->whereNotIn('entity_id', array_map('strval', array_keys($entities))))->delete();

        foreach (DB::table('automation_rule_streaks')->where('rule_id', $ruleId)->get() as $row) {
            $streaks[(string) $row->entity_id] = [
                'days' => (int) (new DateTimeImmutable((string) $row->last_seen_on))->diff(new DateTimeImmutable((string) $row->first_seen_on))->days + 1,
                'acted' => $row->acted_at !== null,
            ];
        }

        return $streaks;
    }

    public function streaks(string $ruleId): array
    {
        $out = [];

        foreach (DB::table('automation_rule_streaks')->where('rule_id', $ruleId)->get() as $row) {
            $out[(string) $row->entity_id] = (int) (new DateTimeImmutable((string) $row->last_seen_on))->diff(new DateTimeImmutable((string) $row->first_seen_on))->days + 1;
        }

        return $out;
    }

    public function markActed(string $ruleId, string $entityId): void
    {
        DB::table('automation_rule_streaks')->where(['rule_id' => $ruleId, 'entity_id' => $entityId])->update(['acted_at' => $this->clock->now()]);
    }

    public function delete(string $workspaceId, string $id): void
    {
        /* Archived, not deleted: its history and change log stay readable. */
        DB::table('automation_rules')->where(['workspace_id' => $workspaceId, 'id' => $id])
            ->update(['archived_at' => $this->clock->now(), 'is_active' => false, 'name' => DB::raw("name || ' · archived ' || left(id::text, 8)")]);
    }

    public function recordRun(string $ruleId, array $result): void
    {
        DB::table('automation_rules')->where('id', $ruleId)
            ->update(['last_run_at' => $this->clock->now(), 'last_result' => json_encode($result, JSON_THROW_ON_ERROR)]);
    }

    private function base(): Builder
    {
        return DB::table('automation_rules as r')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'r.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->select(['r.id', 'r.name', 'r.metric', 'r.operator', 'r.threshold', 'r.window_days', 'r.severity', 'r.is_active', 'r.client_id', 'c.name as client_name', 'r.project_id', 'p.name as project_name', 'u.name as author_name', 'r.conditions', 'r.for_days', 'r.action', 'r.action_pct', 'r.trust_level', 'r.mode', 'r.logic', 'r.providers', 'r.external_account_ids', 'r.campaign_ids', 'r.object_level', 'r.schedule', 'r.schedule_time', 'r.timezone', 'r.cooldown_hours', 'r.last_run_at', 'r.last_result', 'r.min_spend', 'r.min_age_days', 'r.min_conversions', 'r.ad_group_ids', 'r.ad_ids'])
            ->whereNull('r.archived_at');
    }

    /** @return array<string, mixed> */
    private function columns(RuleInput $input): array
    {
        return [
            'name' => $input->name,
            'metric' => $input->metric->value,
            'operator' => ($input->operator ?? $input->metric->breachedWhen())->value,
            'threshold' => $input->metric->needsThreshold() ? $input->threshold : null,
            'window_days' => $input->windowDays,
            'severity' => $input->severity->value,
            'is_active' => $input->isActive,
            /* A rule on a project belongs to its client already; storing both would be two answers to one question. */
            'client_id' => $input->projectId === null ? $input->clientId : null,
            'project_id' => $input->projectId,
            'conditions' => json_encode(array_map(static fn (array $c): array => [
                'metric' => $c['metric']->value,
                'threshold' => $c['metric']->needsThreshold() ? $c['threshold'] : null,
                'operator' => ($c['operator'] ?? $c['metric']->breachedWhen())->value,
            ], $input->conditions), JSON_THROW_ON_ERROR),
            'for_days' => $input->forDays,
            'action' => $input->action->value,
            'action_pct' => $input->action->needsPct() ? $input->actionPct : null,
            'trust_level' => $input->trustLevel->value,
            'mode' => $input->mode->value,
            'logic' => $input->logic,
            'providers' => json_encode(array_values($input->providers), JSON_THROW_ON_ERROR),
            'external_account_ids' => json_encode(array_values($input->accountIds), JSON_THROW_ON_ERROR),
            'campaign_ids' => json_encode(array_values($input->campaignIds), JSON_THROW_ON_ERROR),
            'ad_group_ids' => json_encode(array_values($input->adGroupIds), JSON_THROW_ON_ERROR),
            'ad_ids' => json_encode(array_values($input->adIds), JSON_THROW_ON_ERROR),
            'object_level' => $input->objectLevel,
            'schedule' => $input->schedule->value,
            'schedule_time' => $input->schedule === RuleSchedule::Daily ? ($input->scheduleTime ?? '08:00') : null,
            'timezone' => $input->timezone,
            'cooldown_hours' => $input->cooldownHours,
            'min_spend' => $input->minSpend,
            'min_age_days' => $input->minAgeDays,
            'min_conversions' => $input->minConversions,
        ];
    }

    private function view(stdClass $r): RuleView
    {
        return new RuleView(
            id: (string) $r->id,
            name: (string) $r->name,
            metric: RuleMetric::from((string) $r->metric),
            operator: RuleOperator::from((string) $r->operator),
            threshold: $r->threshold === null ? null : (float) $r->threshold,
            windowDays: (int) $r->window_days,
            severity: RuleSeverity::from((string) $r->severity),
            isActive: (bool) $r->is_active,
            clientId: isset($r->client_id) ? (string) $r->client_id : null,
            clientName: isset($r->client_name) ? (string) $r->client_name : null,
            projectId: isset($r->project_id) ? (string) $r->project_id : null,
            projectName: isset($r->project_name) ? (string) $r->project_name : null,
            authorName: isset($r->author_name) ? (string) $r->author_name : null,
            conditions: $this->conditions($r->conditions ?? '[]'),
            forDays: (int) ($r->for_days ?? 1),
            action: RuleAction::tryFrom((string) ($r->action ?? 'alert')) ?? RuleAction::Alert,
            actionPct: isset($r->action_pct) ? (int) $r->action_pct : null,
            trustLevel: TrustLevel::tryFrom((int) ($r->trust_level ?? 2)) ?? TrustLevel::Propose,
            mode: RuleMode::tryFrom((string) ($r->mode ?? 'recommend')) ?? RuleMode::Recommend,
            logic: ($r->logic ?? 'all') === 'any' ? 'any' : 'all',
            providers: $this->strings($r->providers ?? '[]'),
            accountIds: $this->strings($r->external_account_ids ?? '[]'),
            campaignIds: $this->strings($r->campaign_ids ?? '[]'),
            adGroupIds: $this->strings($r->ad_group_ids ?? '[]'),
            adIds: $this->strings($r->ad_ids ?? '[]'),
            objectLevel: (string) ($r->object_level ?? 'campaign'),
            schedule: RuleSchedule::tryFrom((string) ($r->schedule ?? 'hourly')) ?? RuleSchedule::Hourly,
            scheduleTime: isset($r->schedule_time) ? (string) $r->schedule_time : null,
            timezone: (string) ($r->timezone ?? 'Asia/Riyadh'),
            cooldownHours: (int) ($r->cooldown_hours ?? 24),
            minSpend: isset($r->min_spend) ? (float) $r->min_spend : null,
            minAgeDays: isset($r->min_age_days) ? (int) $r->min_age_days : null,
            minConversions: isset($r->min_conversions) ? (float) $r->min_conversions : null,
            lastRunAt: isset($r->last_run_at) ? new DateTimeImmutable((string) $r->last_run_at) : null,
            lastResult: isset($r->last_result) && is_string($r->last_result) ? (array) json_decode($r->last_result, true) : null,
        );
    }

    /** @return list<string> */
    private function strings(mixed $json): array
    {
        $rows = is_string($json) ? json_decode($json, true) : null;

        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_string'));
    }

    /** @return list<array{metric: RuleMetric, threshold: float|null, operator: RuleOperator}> */
    private function conditions(mixed $json): array
    {
        $rows = is_string($json) ? json_decode($json, true) : null;
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $metric = is_array($row) ? RuleMetric::tryFrom((string) ($row['metric'] ?? '')) : null;

            if ($metric !== null) {
                $out[] = [
                    'metric' => $metric,
                    'threshold' => isset($row['threshold']) && is_numeric($row['threshold']) ? (float) $row['threshold'] : null,
                    'operator' => RuleOperator::tryFrom((string) ($row['operator'] ?? '')) ?? $metric->breachedWhen(),
                ];
            }
        }

        return $out;
    }
}
