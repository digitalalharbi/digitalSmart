<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\SurfaceView\Contract\SurfaceViewRepository;
use App\Application\SurfaceView\DTO\SurfaceViewView;
use App\Domain\Dashboard\Visibility;
use App\Domain\SurfaceView\SurfaceKind;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentSurfaceViewRepository implements SurfaceViewRepository
{
    public function __construct(private IdentifierGenerator $ids, private Clock $clock) {}

    public function visibleTo(string $workspaceId, string $actorId, ?array $visibleProjectIds, SurfaceKind $surface): array
    {
        $rows = DB::table('surface_views as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.created_by')
            ->leftJoin('projects as p', 'p.id', '=', 'v.project_id')
            ->where('v.workspace_id', $workspaceId)
            ->where('v.surface', $surface->value)
            ->where(static function (Builder $q) use ($actorId, $visibleProjectIds): void {
                $q->where('v.created_by', $actorId)
                    ->orWhere('v.visibility', Visibility::Workspace->value);

                if ($visibleProjectIds === null) {
                    $q->orWhere('v.visibility', Visibility::Project->value);
                } elseif ($visibleProjectIds !== []) {
                    $q->orWhere(static fn (Builder $x) => $x->where('v.visibility', Visibility::Project->value)->whereIn('v.project_id', $visibleProjectIds));
                }
            })
            ->orderBy('v.name')
            ->get(['v.id', 'v.surface', 'v.name', 'v.visibility', 'v.project_id', 'p.name as project_name', 'v.filters', 'v.created_by', 'u.name as author_name']);

        return array_values(array_map(fn (stdClass $r): SurfaceViewView => $this->view($r, $actorId), $rows->all()));
    }

    public function create(string $workspaceId, string $actorId, SurfaceKind $surface, string $name, Visibility $visibility, ?string $projectId, array $filters): SurfaceViewView
    {
        $id = $this->ids->generate();
        $now = $this->clock->now();

        DB::table('surface_views')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'created_by' => $actorId, 'project_id' => $projectId,
            'surface' => $surface->value, 'name' => $name, 'visibility' => $visibility->value,
            'filters' => json_encode($filters, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $row = DB::table('surface_views as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.created_by')
            ->leftJoin('projects as p', 'p.id', '=', 'v.project_id')
            ->where('v.id', $id)
            ->first(['v.id', 'v.surface', 'v.name', 'v.visibility', 'v.project_id', 'p.name as project_name', 'v.filters', 'v.created_by', 'u.name as author_name']);

        return $this->view($row instanceof stdClass ? $row : new stdClass, $actorId);
    }

    public function nameTaken(string $workspaceId, string $actorId, SurfaceKind $surface, string $name): bool
    {
        return DB::table('surface_views')->where([
            'workspace_id' => $workspaceId, 'created_by' => $actorId, 'surface' => $surface->value, 'name' => $name,
        ])->exists();
    }

    public function isOwnedBy(string $workspaceId, string $id, string $actorId): bool
    {
        return DB::table('surface_views')->where(['workspace_id' => $workspaceId, 'id' => $id, 'created_by' => $actorId])->exists();
    }

    public function delete(string $workspaceId, string $id): void
    {
        DB::table('surface_views')->where(['workspace_id' => $workspaceId, 'id' => $id])->delete();
    }

    private function view(stdClass $r, string $actorId): SurfaceViewView
    {
        /** @var array<string, string|list<string>> $filters */
        $filters = json_decode((string) ($r->filters ?? '{}'), true) ?: [];

        return new SurfaceViewView(
            id: (string) ($r->id ?? ''),
            surface: SurfaceKind::from((string) ($r->surface ?? SurfaceKind::CampaignControl->value)),
            name: (string) ($r->name ?? ''),
            visibility: Visibility::from((string) ($r->visibility ?? Visibility::Private->value)),
            projectId: isset($r->project_id) ? (string) $r->project_id : null,
            projectName: isset($r->project_name) ? (string) $r->project_name : null,
            filters: $filters,
            authorId: isset($r->created_by) ? (string) $r->created_by : null,
            authorName: isset($r->author_name) ? (string) $r->author_name : null,
            isMine: isset($r->created_by) && (string) $r->created_by === $actorId,
        );
    }
}
