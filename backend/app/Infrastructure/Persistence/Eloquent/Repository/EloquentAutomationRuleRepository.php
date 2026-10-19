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
use App\Domain\Automation\RuleOperator;
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

    public function observe(string $ruleId, array $campaignIds, string $today): array
    {
        $streaks = [];

        foreach ($campaignIds as $campaignId) {
            DB::statement(
                'INSERT INTO automation_rule_streaks (rule_id, campaign_id, first_seen_on, last_seen_on) VALUES (?, ?, ?, ?)
                 ON CONFLICT (rule_id, campaign_id) DO UPDATE SET
                   first_seen_on = CASE WHEN automation_rule_streaks.last_seen_on < (?::date - 1) THEN EXCLUDED.first_seen_on ELSE automation_rule_streaks.first_seen_on END,
                   acted_at = CASE WHEN automation_rule_streaks.last_seen_on < (?::date - 1) THEN NULL ELSE automation_rule_streaks.acted_at END,
                   last_seen_on = EXCLUDED.last_seen_on',
                [$ruleId, $campaignId, $today, $today, $today, $today],
            );
        }

        /* A campaign the rule no longer holds for starts again from nothing next time. */
        $gone = DB::table('automation_rule_streaks')->where('rule_id', $ruleId);
        ($campaignIds === [] ? $gone : $gone->whereNotIn('campaign_id', $campaignIds))->delete();

        foreach (DB::table('automation_rule_streaks')->where('rule_id', $ruleId)->get() as $row) {
            $streaks[(string) $row->campaign_id] = [
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
            $out[(string) $row->campaign_id] = (int) (new DateTimeImmutable((string) $row->last_seen_on))->diff(new DateTimeImmutable((string) $row->first_seen_on))->days + 1;
        }

        return $out;
    }

    public function markActed(string $ruleId, string $campaignId): void
    {
        DB::table('automation_rule_streaks')->where(['rule_id' => $ruleId, 'campaign_id' => $campaignId])->update(['acted_at' => $this->clock->now()]);
    }

    public function delete(string $workspaceId, string $id): void
    {
        DB::table('automation_rules')->where(['workspace_id' => $workspaceId, 'id' => $id])->delete();
    }

    private function base(): Builder
    {
        return DB::table('automation_rules as r')
            ->leftJoin('clients as c', 'c.id', '=', 'r.client_id')
            ->leftJoin('projects as p', 'p.id', '=', 'r.project_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.created_by')
            ->select(['r.id', 'r.name', 'r.metric', 'r.operator', 'r.threshold', 'r.window_days', 'r.severity', 'r.is_active', 'r.client_id', 'c.name as client_name', 'r.project_id', 'p.name as project_name', 'u.name as author_name', 'r.conditions', 'r.for_days', 'r.action', 'r.action_pct', 'r.trust_level']);
    }

    /** @return array<string, mixed> */
    private function columns(RuleInput $input): array
    {
        return [
            'name' => $input->name,
            'metric' => $input->metric->value,
            'operator' => $input->metric->breachedWhen()->value,
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
            ], $input->conditions), JSON_THROW_ON_ERROR),
            'for_days' => $input->forDays,
            'action' => $input->action->value,
            'action_pct' => $input->action === RuleAction::ProposeBudgetCut ? $input->actionPct : null,
            'trust_level' => $input->trustLevel->value,
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
        );
    }

    /** @return list<array{metric: RuleMetric, threshold: float|null}> */
    private function conditions(mixed $json): array
    {
        $rows = is_string($json) ? json_decode($json, true) : null;
        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $metric = is_array($row) ? RuleMetric::tryFrom((string) ($row['metric'] ?? '')) : null;

            if ($metric !== null) {
                $out[] = ['metric' => $metric, 'threshold' => isset($row['threshold']) && is_numeric($row['threshold']) ? (float) $row['threshold'] : null];
            }
        }

        return $out;
    }
}
