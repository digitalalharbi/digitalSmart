<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Agents\Contract\OperatingRuleRepository;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use Illuminate\Support\Facades\DB;

final readonly class EloquentOperatingRuleRepository implements OperatingRuleRepository
{
    public function __construct(private IdentifierGenerator $ids, private Clock $clock) {}

    public function own(string $workspaceId, ?string $clientId, ?string $projectId): ?array
    {
        $row = DB::table('operating_rules')
            ->where('workspace_id', $workspaceId)
            ->where('client_id', $clientId)
            ->where('project_id', $projectId)
            ->first();

        if ($row === null) {
            return null;
        }

        $values = (array) $row;
        $values['forbidden_terms'] = json_decode((string) $values['forbidden_terms'], true) ?: null;
        $values['important_dates'] = json_decode((string) $values['important_dates'], true) ?: null;

        return $values;
    }

    public function clientOf(string $workspaceId, string $projectId): ?string
    {
        $client = DB::table('projects')->where('workspace_id', $workspaceId)->where('id', $projectId)->value('client_id');

        return $client === null ? null : (string) $client;
    }

    public function save(string $workspaceId, string $actorId, ?string $clientId, ?string $projectId, array $values): void
    {
        $now = $this->clock->now();
        $row = [
            'primary_kpi' => $values['primary_kpi'] ?? null,
            'target_cpa_minor' => $values['target_cpa_minor'] ?? null,
            'target_cpl_minor' => $values['target_cpl_minor'] ?? null,
            'target_roas' => $values['target_roas'] ?? null,
            'min_spend_minor' => $values['min_spend_minor'] ?? null,
            'max_daily_budget_minor' => $values['max_daily_budget_minor'] ?? null,
            'max_budget_change_pct' => $values['max_budget_change_pct'] ?? null,
            'revenue_authority' => $values['revenue_authority'] ?? null,
            'forbidden_terms' => json_encode(array_values($values['forbidden_terms'] ?? []), JSON_THROW_ON_ERROR),
            'important_dates' => json_encode(array_values($values['important_dates'] ?? []), JSON_THROW_ON_ERROR),
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];

        $existing = DB::table('operating_rules')
            ->where('workspace_id', $workspaceId)
            ->where('client_id', $clientId)
            ->where('project_id', $projectId);

        if ($existing->exists()) {
            $existing->update($row);

            return;
        }

        DB::table('operating_rules')->insert([
            'id' => $this->ids->generate(),
            'workspace_id' => $workspaceId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'created_at' => $now,
            ...$row,
        ]);
    }
}
