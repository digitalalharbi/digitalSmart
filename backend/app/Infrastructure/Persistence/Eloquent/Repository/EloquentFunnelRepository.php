<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\FunnelRepository;
use App\Domain\Tracking\Funnel\FunnelDefinition;
use App\Domain\Tracking\Funnel\FunnelStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentFunnelRepository implements FunnelRepository
{
    public function save(string $workspaceId, string $projectId, ?string $id, string $name, array $stages, string $actorId): array
    {
        $json = json_encode(array_map(static fn (FunnelStage $s): array => $s->toArray(), $stages), JSON_THROW_ON_ERROR);
        $now = now();

        if ($id === null) {
            $id = (string) Str::uuid7();
            DB::table('funnels')->insert([
                'id' => $id, 'workspace_id' => $workspaceId, 'project_id' => $projectId, 'name' => $name,
                'stages' => $json, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            DB::table('funnels')->where('workspace_id', $workspaceId)->where('id', $id)
                ->update(['name' => $name, 'stages' => $json, 'updated_at' => $now]);
        }

        return ['id' => $id, 'project_id' => $projectId, 'name' => $name, 'stages' => array_map(static fn (FunnelStage $s): array => $s->toArray(), $stages)];
    }

    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $projectId): array
    {
        $query = DB::table('funnels')->where('workspace_id', $workspaceId);

        if ($visibleProjectIds !== null) {
            $query->whereIn('project_id', $visibleProjectIds);
        }

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $out = [];

        foreach ($query->orderBy('name')->limit(200)->get() as $row) {
            $out[] = ['id' => (string) $row->id, 'project_id' => (string) $row->project_id, 'name' => (string) $row->name, 'stages' => $this->raw($row->stages)];
        }

        return $out;
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?array
    {
        $query = DB::table('funnels')->where('workspace_id', $workspaceId)->where('id', $id);

        if ($visibleProjectIds !== null) {
            $query->whereIn('project_id', $visibleProjectIds);
        }

        $row = $query->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'id' => (string) $row->id, 'project_id' => (string) $row->project_id, 'name' => (string) $row->name,
            'stages' => FunnelDefinition::stagesFrom($this->raw($row->stages)),
        ];
    }

    public function delete(string $workspaceId, string $id): void
    {
        DB::table('funnels')->where('workspace_id', $workspaceId)->where('id', $id)->delete();
    }

    /** @return list<array<string, mixed>> */
    private function raw(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : [];

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
