<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Audit\Contract\AuditReadModel;
use App\Application\Audit\DTO\AuditEntryView;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Audit\AuditAction;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final class EloquentAuditReadModel implements AuditReadModel
{
    /**
     * @param  list<string>  $actions
     * @return Paginated<AuditEntryView>
     */
    public function paginate(
        WorkspaceId $workspaceId,
        ?string $search,
        array $actions,
        ?string $actorId,
        ?bool $securityOnly,
        Pagination $pagination,
    ): Paginated {
        $query = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.actor_id')
            ->select([
                'audit_logs.id', 'audit_logs.action', 'audit_logs.actor_id',
                'audit_logs.auditable_type', 'audit_logs.auditable_id', 'audit_logs.summary',
                'audit_logs.changes', 'audit_logs.ip_address', 'audit_logs.request_id',
                'audit_logs.created_at', 'users.name as actor_name',
            ])
            ->where('audit_logs.workspace_id', $workspaceId->value);

        if ($search !== null && trim($search) !== '') {
            $term = '%'.strtolower(trim($search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('lower(coalesce(audit_logs.summary, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(users.name, \'\')) like ?', [$term]);
            });
        }

        if ($actions !== []) {
            $query->whereIn('audit_logs.action', $actions);
        }

        if ($actorId !== null) {
            $query->where('audit_logs.actor_id', $actorId);
        }

        if ($securityOnly === true) {
            $query->whereIn('audit_logs.action', $this->securityActions());
        }

        $total = (clone $query)->count();

        // Newest first: an audit log is read from the most recent event back.
        $rows = $query
            ->orderByDesc('audit_logs.created_at')
            ->orderByDesc('audit_logs.id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        /** @var list<AuditEntryView> $items */
        $items = $rows->map(fn (stdClass $row): AuditEntryView => $this->toView($row))->values()->all();

        return new Paginated($items, $total, $pagination->page, $pagination->perPage);
    }

    /** @return list<string> */
    private function securityActions(): array
    {
        return array_values(array_map(
            static fn (AuditAction $action): string => $action->value,
            array_filter(AuditAction::cases(), static fn (AuditAction $a): bool => $a->isSecurityEvent()),
        ));
    }

    private function toView(stdClass $row): AuditEntryView
    {
        $action = AuditAction::tryFrom((string) $row->action);

        /** @var array<string, mixed> $changes */
        $changes = is_string($row->changes) ? (array) json_decode($row->changes, true) : [];

        return new AuditEntryView(
            id: (string) $row->id,
            action: (string) $row->action,
            group: $action?->group() ?? 'unknown',
            isSecurityEvent: $action?->isSecurityEvent() ?? false,
            actorId: is_string($row->actor_id) ? $row->actor_id : null,
            actorName: is_string($row->actor_name) ? $row->actor_name : null,
            auditableType: is_string($row->auditable_type) ? $row->auditable_type : null,
            auditableId: is_string($row->auditable_id) ? $row->auditable_id : null,
            summary: is_string($row->summary) ? $row->summary : null,
            changes: $changes,
            ipAddress: is_string($row->ip_address) ? $row->ip_address : null,
            requestId: is_string($row->request_id) ? $row->request_id : null,
            createdAt: is_string($row->created_at)
                ? (new DateTimeImmutable($row->created_at))->format(DateTimeInterface::ATOM)
                : null,
        );
    }
}
