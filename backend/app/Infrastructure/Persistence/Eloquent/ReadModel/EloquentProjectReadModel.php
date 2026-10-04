<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Project\Contract\ProjectReadModel;
use App\Application\Project\DTO\ProjectFilters;
use App\Application\Project\DTO\ProjectView;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentProjectReadModel implements ProjectReadModel
{
    /** Columns a caller may sort by. Anything else is ignored, never interpolated. */
    private const SORTABLE = ['name', 'status', 'starts_on', 'ends_on', 'created_at', 'updated_at'];

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return Paginated<ProjectView>
     */
    public function paginate(
        WorkspaceId $workspaceId,
        UserId $actorId,
        ?array $visibleProjectIds,
        ProjectFilters $filters,
        Pagination $pagination,
    ): Paginated {
        $query = $this->baseQuery($workspaceId, $actorId, $visibleProjectIds);

        if ($filters->search !== null && trim($filters->search) !== '') {
            $term = '%'.strtolower(trim($filters->search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('lower(projects.name) like ?', [$term])
                    ->orWhereRaw('lower(clients.name) like ?', [$term]);
            });
        }

        if ($filters->statuses !== []) {
            $query->whereIn('projects.status', $filters->statusValues());
        }

        if ($filters->clientId !== null) {
            $query->where('projects.client_id', $filters->clientId);
        }

        if ($filters->isDemo !== null) {
            $query->where('projects.is_demo', $filters->isDemo);
        }

        $total = (clone $query)->count();

        $sort = in_array($filters->sort, self::SORTABLE, true) ? $filters->sort : 'name';
        $direction = strtolower($filters->direction) === 'desc' ? 'desc' : 'asc';

        $rows = $query
            ->orderBy('projects.'.$sort, $direction)
            ->orderBy('projects.id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        /** @var list<ProjectView> $items */
        $items = $rows->map(fn (stdClass $row): ProjectView => $this->toView($row))->values()->all();

        return new Paginated(
            items: $items,
            total: $total,
            page: $pagination->page,
            perPage: $pagination->perPage,
        );
    }

    /** @param list<string>|null $visibleProjectIds */
    public function find(
        WorkspaceId $workspaceId,
        UserId $actorId,
        ?array $visibleProjectIds,
        ProjectId $projectId,
    ): ?ProjectView {
        $row = $this->baseQuery($workspaceId, $actorId, $visibleProjectIds)
            ->where('projects.id', $projectId->value)
            ->first();

        return $row instanceof stdClass ? $this->toView($row) : null;
    }

    /** @param list<string>|null $visibleProjectIds */
    private function baseQuery(WorkspaceId $workspaceId, UserId $actorId, ?array $visibleProjectIds): Builder
    {
        $query = DB::table('projects')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->select([
                'projects.id', 'projects.client_id', 'projects.name', 'projects.slug',
                'projects.status', 'projects.currency', 'projects.description',
                'projects.starts_on', 'projects.ends_on', 'projects.is_demo',
                'projects.created_at', 'projects.updated_at',
                'clients.name as client_name',
            ])
            ->selectSub(
                DB::table('project_members')
                    ->selectRaw('count(*)')
                    ->whereColumn('project_members.project_id', 'projects.id'),
                'member_count',
            )
            ->selectSub(
                DB::table('project_members')
                    ->selectRaw('count(*)')
                    ->whereColumn('project_members.project_id', 'projects.id')
                    ->where('project_members.user_id', $actorId->value),
                'assignment_count',
            )
            ->where('projects.workspace_id', $workspaceId->value)
            ->whereNull('projects.deleted_at')
            ->whereNull('clients.deleted_at');

        if ($visibleProjectIds === []) {
            /*
             * Restricted visibility with no assignments must match nothing.
             * Stated explicitly rather than relying on whereIn([]), and never
             * with a placeholder value — the column is a UUID, so a sentinel
             * string is not even a valid comparison.
             */
            $query->whereRaw('false');
        } elseif ($visibleProjectIds !== null) {
            $query->whereIn('projects.id', $visibleProjectIds);
        }

        return $query;
    }

    private function toView(stdClass $row): ProjectView
    {
        return new ProjectView(
            id: (string) $row->id,
            clientId: (string) $row->client_id,
            clientName: (string) $row->client_name,
            name: (string) $row->name,
            slug: (string) $row->slug,
            status: (string) $row->status,
            currency: (string) $row->currency,
            description: $this->nullableString($row->description),
            startsOn: $this->toDate($row->starts_on),
            endsOn: $this->toDate($row->ends_on),
            isDemo: (bool) $row->is_demo,
            memberCount: (int) $row->member_count,
            isAssigned: ((int) $row->assignment_count) > 0,
            createdAt: $this->toIso($row->created_at),
            updatedAt: $this->toIso($row->updated_at),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private function toIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    }
}
