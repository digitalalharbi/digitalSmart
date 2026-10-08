<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Client\Contract\ClientVisibility;
use App\Application\Portal\Contract\PortalRepository;
use App\Application\Portal\DTO\ModuleExposure;
use App\Application\Portal\DTO\PortalSummary;
use App\Application\Portal\DTO\PortalView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Portal\PortalModule;
use App\Domain\Portal\PortalState;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Client portals and their module grants.
 *
 * Three decisions worth reading.
 *
 * **The list is driven by clients, not by portals.** A left join from `clients`, so a client with no portal row
 * appears in state `NotConfigured` rather than being absent. The surface exists to answer "which clients still
 * cannot see anything", and a query that started from `client_portals` could never answer it.
 *
 * **Prerequisites are counted in SQL, in the same query as the grants.** One `whereExists` per module against the
 * thing that module reads from. Resolving them per client in PHP would be three queries times however many
 * clients a workspace has, which is the shape of every list page that ends up needing a cache.
 *
 * **Narrowing is applied through the client's projects.** The same rule as everywhere else — `null` is the whole
 * workspace, `[]` is nothing — expressed against `projects.client_id`, because a portal is about a client and a
 * reader's visibility is about projects.
 */
final readonly class EloquentPortalRepository implements PortalRepository
{
    public function __construct(
        private IdentifierGenerator $identifiers,
        private ClientVisibility $clients,
    ) {}

    /** @return list<PortalSummary> */
    public function summaries(string $workspaceId, ?array $visibleProjectIds): array
    {
        $rows = $this->clients($workspaceId, $visibleProjectIds)
            ->orderBy('c.name')
            ->get();

        /*
         * Every grant in the workspace in one query, grouped here. A list page that read a client's grants per
         * row would be the same page that later needs a cache to be usable.
         */
        $byPortal = $this->grantsByPortal($workspaceId);

        $values = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;

            $portalId = $record['portal_id'] === null ? null : (string) $record['portal_id'];
            $exposed = $portalId === null ? [] : array_keys($byPortal[$portalId] ?? []);
            $readiness = $this->readiness($record);

            $empty = 0;

            foreach ($exposed as $module) {
                if ($readiness[(string) $module] === false) {
                    $empty++;
                }
            }

            $values[] = new PortalSummary(
                clientId: (string) ($record['id'] ?? ''),
                clientName: (string) ($record['name'] ?? ''),
                state: $this->state($record),
                exposedCount: count($exposed),
                emptyCount: $empty,
            );
        }

        return $values;
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $clientId): ?PortalView
    {
        $row = $this->clients($workspaceId, $visibleProjectIds)->where('c.id', $clientId)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $record */
        $record = (array) $row;

        $portalId = $record['portal_id'] === null ? null : (string) $record['portal_id'];
        $readiness = $this->readiness($record);
        $grants = $this->grants($portalId);

        $modules = [];

        foreach (PortalModule::cases() as $module) {
            $grant = $grants[$module->value] ?? null;

            $modules[] = new ModuleExposure(
                module: $module,
                isExposed: $grant !== null,
                exposedAt: $grant === null ? null : $this->moment((string) $grant['exposed_at']),
                exposedByName: $grant === null || $grant['exposed_by_name'] === null
                    ? null
                    : (string) $grant['exposed_by_name'],
                isPrerequisiteMet: $readiness[$module->value],
            );
        }

        return new PortalView(
            clientId: (string) ($record['id'] ?? ''),
            clientName: (string) ($record['name'] ?? ''),
            state: $this->state($record),
            modules: $modules,
            enabledAt: $record['enabled_at'] === null ? null : $this->moment((string) $record['enabled_at']),
            enabledByName: $record['enabled_by_name'] === null ? null : (string) $record['enabled_by_name'],
            suspendedAt: $record['suspended_at'] === null ? null : $this->moment((string) $record['suspended_at']),
            suspendedByName: $record['suspended_by_name'] === null
                ? null
                : (string) $record['suspended_by_name'],
            suspendedReason: $record['suspended_reason'] === null
                ? null
                : (string) $record['suspended_reason'],
        );
    }

    public function idFor(string $workspaceId, string $clientId): ?string
    {
        $id = DB::table('client_portals')
            ->where('workspace_id', $workspaceId)
            ->where('client_id', $clientId)
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    public function enable(
        string $workspaceId,
        string $clientId,
        string $actorId,
        DateTimeImmutable $at,
    ): string {
        $id = $this->identifiers->generate();

        DB::table('client_portals')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $clientId,
            'state' => PortalState::Active->value,
            'enabled_at' => $at,
            'enabled_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    public function suspend(string $portalId, string $reason, string $actorId, DateTimeImmutable $at): void
    {
        DB::table('client_portals')->where('id', $portalId)->update([
            'state' => PortalState::Suspended->value,
            'suspended_at' => $at,
            'suspended_by' => $actorId,
            'suspended_reason' => $reason,
            'updated_at' => $at,
        ]);
    }

    public function resume(string $portalId, DateTimeImmutable $at): void
    {
        /*
         * The suspension columns are cleared, which the CHECK on this table requires: a reason left behind on an
         * active portal reads as a current one to whoever opens the record next. The history of the suspension is
         * in the audit trail, where it is permanent.
         */
        DB::table('client_portals')->where('id', $portalId)->update([
            'state' => PortalState::Active->value,
            'suspended_at' => null,
            'suspended_by' => null,
            'suspended_reason' => null,
            'updated_at' => $at,
        ]);
    }

    public function expose(
        string $workspaceId,
        string $portalId,
        PortalModule $module,
        string $actorId,
        DateTimeImmutable $at,
    ): bool {
        $exists = DB::table('client_portal_modules')
            ->where('client_portal_id', $portalId)
            ->where('module', $module->value)
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('client_portal_modules')->insert([
            'id' => $this->identifiers->generate(),
            'workspace_id' => $workspaceId,
            'client_portal_id' => $portalId,
            'module' => $module->value,
            'exposed_at' => $at,
            'exposed_by' => $actorId,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return true;
    }

    public function withdraw(string $portalId, PortalModule $module): bool
    {
        return DB::table('client_portal_modules')
            ->where('client_portal_id', $portalId)
            ->where('module', $module->value)
            ->delete() > 0;
    }

    /** @return array<string, list<PortalModule>> */
    public function exposedFor(string $workspaceId, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = DB::table('client_portal_modules as m')
            ->join('client_portals as p', 'p.id', '=', 'm.client_portal_id')
            ->where('p.workspace_id', $workspaceId)
            ->whereIn('p.client_id', $clientIds)
            /*
             * Only an active portal. A suspended one is reported through `stateFor()`: a client whose portal was
             * switched off is owed a sentence, and an empty module list would render as "nothing has happened yet".
             */
            ->where('p.state', PortalState::Active->value)
            ->get(['p.client_id', 'm.module']);

        $exposed = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;

            $module = PortalModule::tryFrom((string) ($record['module'] ?? ''));

            if ($module === null) {
                continue;
            }

            $exposed[(string) ($record['client_id'] ?? '')][] = $module;
        }

        return $exposed;
    }

    /** @return array<string, string> */
    public function clientsVisibleTo(string $workspaceId, ?array $visibleProjectIds): array
    {
        /*
         * Delegated. "May this reader see this client" is one question several modules ask, and three SQL
         * expressions of it would eventually be three rules with the loosest one deciding.
         */
        return $this->clients->visible($workspaceId, $visibleProjectIds);
    }

    /** @return array<string, PortalState> */
    public function stateFor(string $workspaceId, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = DB::table('client_portals')
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_id', $clientIds)
            ->get(['client_id', 'state']);

        $states = [];

        foreach ($clientIds as $clientId) {
            // The default is the absence of a row, which is a state in its own right.
            $states[$clientId] = PortalState::NotConfigured;
        }

        foreach ($rows as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;

            $states[(string) ($record['client_id'] ?? '')] = PortalState::from((string) ($record['state'] ?? ''));
        }

        return $states;
    }

    /**
     * Every visible client with its portal row, if any, and one flag per module prerequisite.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function clients(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('clients as c')
            ->leftJoin('client_portals as p', 'p.client_id', '=', 'c.id')
            ->leftJoin('users as eu', 'eu.id', '=', 'p.enabled_by')
            ->leftJoin('users as su', 'su.id', '=', 'p.suspended_by')
            ->where('c.workspace_id', $workspaceId)
            ->select([
                'c.id',
                'c.name',
                'p.id as portal_id',
                'p.state',
                'p.enabled_at',
                'p.suspended_at',
                'p.suspended_reason',
                'eu.name as enabled_by_name',
                'su.name as suspended_by_name',
            ])
            /* One existence check per module, resolved in the same round trip as the row. */
            ->selectRaw(
                '(EXISTS (SELECT 1 FROM project_integration_bindings b'
                .' JOIN projects pr ON pr.id = b.project_id'
                .' WHERE pr.client_id = c.id AND pr.workspace_id = ?)) as has_bound_account',
                [$workspaceId],
            )
            ->selectRaw(
                '(EXISTS (SELECT 1 FROM stores s WHERE s.client_id = c.id AND s.is_active)) as has_store',
            )
            ->selectRaw(
                '(EXISTS (SELECT 1 FROM measurement_sources ms'
                .' WHERE ms.client_id = c.id AND ms.is_active)) as has_measurement',
            );

        if ($visibleProjectIds === null) {
            return $builder;
        }

        if ($visibleProjectIds === []) {
            return $builder->whereRaw('1 = 0');
        }

        return $builder->whereIn('c.id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
            $sub->select('client_id')
                ->from('projects')
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $visibleProjectIds);
        });
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, bool>
     */
    private function readiness(array $record): array
    {
        $bound = (bool) ($record['has_bound_account'] ?? false);
        $store = (bool) ($record['has_store'] ?? false);
        $measurement = (bool) ($record['has_measurement'] ?? false);

        return [
            PortalModule::Performance->value => $bound,
            PortalModule::StoreRevenue->value => $store,
            PortalModule::Tracking->value => $measurement,
            /* Nothing has to exist first: a report is a document the agency already wrote for this client. */
            PortalModule::Reports->value => true,
        ];
    }

    /** @param  array<string, mixed>  $record */
    private function state(array $record): PortalState
    {
        $state = $record['state'] ?? null;

        return $state === null ? PortalState::NotConfigured : PortalState::from((string) $state);
    }

    /**
     * Every portal's grants in the workspace, keyed by portal id and then by module.
     *
     * @return array<string, array<string, true>>
     */
    private function grantsByPortal(string $workspaceId): array
    {
        $rows = DB::table('client_portal_modules')
            ->where('workspace_id', $workspaceId)
            ->get(['client_portal_id', 'module']);

        $byPortal = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;

            $byPortal[(string) ($record['client_portal_id'] ?? '')][(string) ($record['module'] ?? '')] = true;
        }

        return $byPortal;
    }

    /**
     * The grant rows for a portal, keyed by module.
     *
     * @return array<string, array{module: string, exposed_at: string, exposed_by_name: ?string}>
     */
    private function grants(?string $portalId): array
    {
        if ($portalId === null) {
            return [];
        }

        $rows = DB::table('client_portal_modules as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.exposed_by')
            ->where('m.client_portal_id', $portalId)
            ->get(['m.module', 'm.exposed_at', 'u.name as exposed_by_name']);

        $grants = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $record */
            $record = (array) $row;

            $grants[(string) ($record['module'] ?? '')] = [
                'module' => (string) ($record['module'] ?? ''),
                'exposed_at' => (string) ($record['exposed_at'] ?? ''),
                'exposed_by_name' => $record['exposed_by_name'] === null
                    ? null
                    : (string) $record['exposed_by_name'],
            ];
        }

        return $grants;
    }

    private function moment(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
