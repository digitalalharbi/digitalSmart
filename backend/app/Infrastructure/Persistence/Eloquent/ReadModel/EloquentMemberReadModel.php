<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Application\Workspace\Contract\MemberReadModel;
use App\Application\Workspace\DTO\MemberView;
use App\Domain\Workspace\MembershipStatus;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentMemberReadModel implements MemberReadModel
{
    /** @return Paginated<MemberView> */
    public function paginate(
        WorkspaceId $workspaceId,
        ?string $search,
        ?string $roleSlug,
        ?string $status,
        Pagination $pagination,
    ): Paginated {
        $query = DB::table('workspace_members')
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->join('roles', 'roles.id', '=', 'workspace_members.role_id')
            ->select([
                'workspace_members.user_id', 'workspace_members.status', 'workspace_members.joined_at',
                'users.name', 'users.email', 'users.job_title', 'users.is_active', 'users.last_login_at',
                'roles.slug as role_slug', 'roles.name as role_name',
            ])
            ->selectSub(
                DB::table('project_members')
                    ->join('projects', 'projects.id', '=', 'project_members.project_id')
                    ->selectRaw('count(*)')
                    ->whereColumn('project_members.user_id', 'workspace_members.user_id')
                    ->whereColumn('projects.workspace_id', 'workspace_members.workspace_id')
                    ->whereNull('projects.deleted_at'),
                'project_count',
            )
            ->where('workspace_members.workspace_id', $workspaceId->value)
            ->whereNull('users.deleted_at');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.strtolower(trim($search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('lower(users.name) like ?', [$term])
                    ->orWhereRaw('lower(users.email) like ?', [$term]);
            });
        }

        if ($roleSlug !== null && $roleSlug !== '') {
            $query->where('roles.slug', $roleSlug);
        }

        if ($status !== null && $status !== '') {
            $query->where('workspace_members.status', $status);
        } else {
            /* People who left are a filter away, not mixed into the people who are here. */
            $query->where('workspace_members.status', '<>', MembershipStatus::Revoked->value);
        }

        $total = (clone $query)->count();

        $rows = $query
            ->orderBy('users.name')
            ->orderBy('workspace_members.user_id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        /** @var list<MemberView> $items */
        $items = $rows->map(fn (stdClass $row): MemberView => new MemberView(
            userId: (string) $row->user_id,
            name: (string) $row->name,
            email: (string) $row->email,
            status: (string) $row->status,
            roleSlug: (string) $row->role_slug,
            roleName: (string) $row->role_name,
            jobTitle: is_string($row->job_title) && $row->job_title !== '' ? $row->job_title : null,
            isActive: (bool) $row->is_active,
            lastLoginAt: $this->toIso($row->last_login_at),
            joinedAt: $this->toIso($row->joined_at),
            projectCount: (int) $row->project_count,
        ))->values()->all();

        return new Paginated($items, $total, $pagination->page, $pagination->perPage);
    }

    private function toIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    }
}
