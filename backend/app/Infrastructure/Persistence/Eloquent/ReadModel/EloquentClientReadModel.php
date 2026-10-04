<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Client\Contract\ClientReadModel;
use App\Application\Client\DTO\ClientFilters;
use App\Application\Client\DTO\ClientView;
use App\Application\Shared\DTO\Paginated;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Client\ClientId;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Query-builder read side.
 *
 * Project counts come from a correlated subquery rather than loading relations,
 * so a page of clients is one query regardless of page size.
 */
final class EloquentClientReadModel implements ClientReadModel
{
    /** Columns a caller may sort by. Anything else is ignored, never interpolated. */
    private const SORTABLE = ['name', 'status', 'created_at', 'updated_at'];

    /** @return Paginated<ClientView> */
    public function paginate(WorkspaceId $workspaceId, ClientFilters $filters, Pagination $pagination): Paginated
    {
        $query = $this->baseQuery($workspaceId);

        if ($filters->search !== null && trim($filters->search) !== '') {
            $term = '%'.strtolower(trim($filters->search)).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('lower(clients.name) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(clients.legal_name, \'\')) like ?', [$term])
                    ->orWhereRaw('lower(coalesce(clients.industry, \'\')) like ?', [$term]);
            });
        }

        if ($filters->statuses !== []) {
            $query->whereIn('clients.status', $filters->statusValues());
        }

        if ($filters->isDemo !== null) {
            $query->where('clients.is_demo', $filters->isDemo);
        }

        $total = (clone $query)->count();

        $sort = in_array($filters->sort, self::SORTABLE, true) ? $filters->sort : 'name';
        $direction = strtolower($filters->direction) === 'desc' ? 'desc' : 'asc';

        $rows = $query
            ->orderBy('clients.'.$sort, $direction)
            // Deterministic tie-break, so pagination cannot repeat or skip rows.
            ->orderBy('clients.id')
            ->offset($pagination->offset())
            ->limit($pagination->perPage)
            ->get();

        /** @var list<ClientView> $items */
        $items = $rows->map(fn (stdClass $row): ClientView => $this->toView($row))->values()->all();

        return new Paginated(
            items: $items,
            total: $total,
            page: $pagination->page,
            perPage: $pagination->perPage,
        );
    }

    public function find(WorkspaceId $workspaceId, ClientId $clientId): ?ClientView
    {
        $row = $this->baseQuery($workspaceId)->where('clients.id', $clientId->value)->first();

        return $row instanceof stdClass ? $this->toView($row) : null;
    }

    private function baseQuery(WorkspaceId $workspaceId): Builder
    {
        return DB::table('clients')
            ->select([
                'clients.id', 'clients.name', 'clients.slug', 'clients.status', 'clients.legal_name',
                'clients.industry', 'clients.website', 'clients.primary_contact_name',
                'clients.primary_contact_email', 'clients.primary_contact_phone',
                'clients.default_currency', 'clients.notes', 'clients.is_demo',
                'clients.created_at', 'clients.updated_at',
            ])
            ->selectSub(
                DB::table('projects')
                    ->selectRaw('count(*)')
                    ->whereColumn('projects.client_id', 'clients.id')
                    ->whereNull('projects.deleted_at'),
                'project_count',
            )
            ->where('clients.workspace_id', $workspaceId->value)
            ->whereNull('clients.deleted_at');
    }

    private function toView(stdClass $row): ClientView
    {
        return new ClientView(
            id: (string) $row->id,
            name: (string) $row->name,
            slug: (string) $row->slug,
            status: (string) $row->status,
            legalName: $this->nullableString($row->legal_name),
            industry: $this->nullableString($row->industry),
            website: $this->nullableString($row->website),
            primaryContactName: $this->nullableString($row->primary_contact_name),
            primaryContactEmail: $this->nullableString($row->primary_contact_email),
            primaryContactPhone: $this->nullableString($row->primary_contact_phone),
            defaultCurrency: $this->nullableString($row->default_currency),
            notes: $this->nullableString($row->notes),
            isDemo: (bool) $row->is_demo,
            projectCount: (int) $row->project_count,
            createdAt: $this->toIso($row->created_at),
            updatedAt: $this->toIso($row->updated_at),
        );
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function toIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    }
}
