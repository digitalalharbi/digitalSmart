<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\StoreReadTarget;
use App\Application\Commerce\DTO\StoreRecord;
use App\Application\Commerce\DTO\StoreView;
use App\Domain\Integration\Provider;
use App\Domain\Workspace\WorkspaceId;

/**
 * The stores a workspace reads, and the one way a new one is written.
 *
 * Until this existed a `stores` row could only be inserted by a seeder or by hand: the webhook receiver looks a
 * store up, the sync reads one, the analytics narrow by one, and nothing in the product could create one. So a
 * workspace that connected Salla had a connection and no store, and every order its webhooks delivered was
 * rejected as `unknown_store` — a provider integration that could never produce a figure.
 */
interface StoreDirectory
{
    /**
     * The stores a reader may see, newest first.
     *
     * Narrowed the way the revenue figures are: a store belongs to a client, and a person reaches a client
     * through a project. The list and the figures have to agree about who can see what.
     *
     * @param  list<string>|null  $visibleProjectIds  null means every project in the workspace
     * @return list<StoreView>
     */
    public function forWorkspace(string $workspaceId, ?array $visibleProjectIds, ?string $clientId): array;

    /**
     * One store, or null where the reader cannot see it.
     *
     * @param  list<string>|null  $visibleProjectIds  null means every project in the workspace
     */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $storeId): ?StoreView;

    /**
     * Register a store against a client.
     *
     * Returns null where this workspace already reads that store, which is a `422` naming the client it
     * belongs to rather than a constraint violation surfacing as a `500`.
     */
    public function register(
        string $workspaceId,
        string $clientId,
        Provider $provider,
        StoreRecord $store,
        ?string $providerConnectionId,
    ): ?StoreView;

    /**
     * Every store a scheduled pass can read, optionally in one workspace.
     *
     * Only the active ones whose platform has a sync adapter: a store nobody can read is not a failure — its
     * webhooks still arrive — and queueing a job that can only answer "skipped" would fill the audit trail
     * with reads nobody asked for.
     *
     * @return list<StoreReadTarget>
     */
    public function readTargets(?WorkspaceId $workspaceId = null): array;

    /** Which client already reads this store in this workspace, if any. */
    public function registeredClientName(string $workspaceId, Provider $provider, string $externalId): ?string;
}
