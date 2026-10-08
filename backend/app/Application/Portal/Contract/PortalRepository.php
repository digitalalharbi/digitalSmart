<?php

declare(strict_types=1);

namespace App\Application\Portal\Contract;

use App\Application\Portal\DTO\PortalSummary;
use App\Application\Portal\DTO\PortalView;
use App\Domain\Portal\PortalModule;
use DateTimeImmutable;

/**
 * Reading and changing what a client's portal exposes.
 *
 * `$visibleProjectIds` carries the same meaning as everywhere else in this product: `null` is the whole workspace,
 * `[]` is nothing, and a list narrows to the clients owning those projects. A portal is about a client, so the
 * narrowing is applied through that client's projects rather than to the portal row.
 */
interface PortalRepository
{
    /**
     * Every visible client with its portal state, including the clients that have no portal.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<PortalSummary>
     */
    public function summaries(string $workspaceId, ?array $visibleProjectIds): array;

    /**
     * One client's portal, or null when that client is not visible to the caller.
     *
     * A visible client with no portal row answers with a `NotConfigured` view rather than null: the absence of a
     * portal is a state worth rendering, not a missing resource.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $clientId): ?PortalView;

    /** The portal row's id, or null when this client has none. */
    public function idFor(string $workspaceId, string $clientId): ?string;

    /** Creates the portal row in `Active` with no modules exposed. Returns its id. */
    public function enable(
        string $workspaceId,
        string $clientId,
        string $actorId,
        DateTimeImmutable $at,
    ): string;

    public function suspend(
        string $portalId,
        string $reason,
        string $actorId,
        DateTimeImmutable $at,
    ): void;

    public function resume(string $portalId, DateTimeImmutable $at): void;

    /** True when the module was not already exposed, so the caller knows whether to record a disclosure. */
    public function expose(
        string $workspaceId,
        string $portalId,
        PortalModule $module,
        string $actorId,
        DateTimeImmutable $at,
    ): bool;

    /** True when a row was removed, so the caller knows whether anything actually changed. */
    public function withdraw(string $portalId, PortalModule $module): bool;

    /**
     * What a client may read, for the client themselves.
     *
     * Keyed by client id, so a reader assigned to two clients' projects gets both rather than one chosen for them.
     * Only `Active` portals appear; a suspended one is reported through {@see stateFor()} instead, because a client
     * whose portal was switched off is owed a sentence rather than an empty list.
     *
     * @param  list<string>  $clientIds
     * @return array<string, list<PortalModule>>
     */
    public function exposedFor(string $workspaceId, array $clientIds): array;

    /**
     * The clients a reader may reach, keyed by id and ordered by name.
     *
     * `null` is the whole workspace and `[]` is nothing, as everywhere else. The narrowing is expressed through
     * `projects.client_id`, because a portal is about a client and a reader's visibility is about projects.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, string>
     */
    public function clientsVisibleTo(string $workspaceId, ?array $visibleProjectIds): array;

    /**
     * The portal state of each of these clients, including the ones with no portal row.
     *
     * @param  list<string>  $clientIds
     * @return array<string, \App\Domain\Portal\PortalState>
     */
    public function stateFor(string $workspaceId, array $clientIds): array;
}
