<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleOperator;
use App\Domain\Automation\RuleSeverity;
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
            ->select(['r.id', 'r.name', 'r.metric', 'r.operator', 'r.threshold', 'r.window_days', 'r.severity', 'r.is_active', 'r.client_id', 'c.name as client_name', 'r.project_id', 'p.name as project_name', 'u.name as author_name']);
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
        );
    }
}
