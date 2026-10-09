<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry;
use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\DTO\StoreReadTarget;
use App\Application\Commerce\DTO\StoreRecord;
use App\Application\Commerce\DTO\StoreView;
use App\Domain\Integration\Provider;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentStoreDirectory implements StoreDirectory
{
    public function __construct(private CommerceSyncAdapterRegistry $adapters) {}

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<StoreView>
     */
    public function forWorkspace(string $workspaceId, ?array $visibleProjectIds, ?string $clientId): array
    {
        $rows = $this->visible($workspaceId, $visibleProjectIds)
            ->when($clientId !== null, fn (Builder $q): Builder => $q->where('s.client_id', $clientId))
            ->orderBy('s.created_at')
            ->get();

        return array_values($rows->map(fn (stdClass $row): StoreView => $this->view($row))->all());
    }

    /** @param  list<string>|null  $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $storeId): ?StoreView
    {
        /** @var stdClass|null $row */
        $row = $this->visible($workspaceId, $visibleProjectIds)->where('s.id', $storeId)->first();

        return $row === null ? null : $this->view($row);
    }

    public function register(
        string $workspaceId,
        string $clientId,
        Provider $provider,
        StoreRecord $store,
        ?string $providerConnectionId,
    ): ?StoreView {
        /*
         * Checked before the insert so the refusal can name the client, and the unique index is still there
         * behind it: this check loses a race, the index does not.
         */
        if ($this->registeredClientName($workspaceId, $provider, $store->externalId) !== null) {
            return null;
        }

        $id = (string) Str::uuid7();

        DB::table('stores')->insert([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $clientId,
            'provider_connection_id' => $providerConnectionId,
            'provider' => $provider->value,
            'external_id' => $store->externalId,
            'name' => $store->name,
            'domain' => $store->domain,
            'currency' => $store->currency,
            'timezone' => $store->timezone,
            'is_active' => true,
            /*
             * Deliberately null, both of them. A store registered a second ago has not been read, and a
             * timestamp written here would make the first page somebody sees claim otherwise.
             */
            'last_synced_at' => null,
            'orders_synced_through' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->find($workspaceId, null, $id);
    }

    /** @return list<StoreReadTarget> */
    public function readTargets(?WorkspaceId $workspaceId = null): array
    {
        $query = DB::table('stores')->where('is_active', true);

        if ($workspaceId !== null) {
            $query->where('workspace_id', $workspaceId->value);
        }

        $rows = $query
            ->orderBy('created_at')
            ->get(['id', 'workspace_id', 'provider', 'name']);

        $targets = [];

        foreach ($rows as $row) {
            /*
             * Filtered here rather than in SQL: which platforms can be read is the adapter registry's answer,
             * and encoding today's list as a `whereIn` would be a second copy of it to keep in step.
             */
            if (! $this->adapters->supports(Provider::from((string) $row->provider))) {
                continue;
            }

            $targets[] = new StoreReadTarget(
                workspaceId: new WorkspaceId((string) $row->workspace_id),
                storeId: (string) $row->id,
                name: (string) $row->name,
            );
        }

        return $targets;
    }

    public function registeredClientName(string $workspaceId, Provider $provider, string $externalId): ?string
    {
        $name = DB::table('stores as s')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->where('s.workspace_id', $workspaceId)
            ->where('s.provider', $provider->value)
            ->where('s.external_id', $externalId)
            ->value('c.name');

        return $name === null ? null : (string) $name;
    }

    /**
     * Narrowed the way the revenue figures are.
     *
     * A store belongs to a client and a person reaches a client through a project, so the visible set is the
     * clients of the actor's projects — the same subquery `EloquentStoreAnalyticsReadModel` uses, written the
     * same way, because a list that disagreed with the figures beside it would be the worse of the two bugs.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function visible(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $builder = DB::table('stores as s')
            ->join('clients as c', 'c.id', '=', 's.client_id')
            ->where('s.workspace_id', $workspaceId)
            ->select([
                's.id', 's.client_id', 's.provider', 's.external_id', 's.name', 's.domain',
                's.currency', 's.timezone', 's.is_active', 's.last_synced_at', 's.orders_synced_through',
                'c.name as client_name',
            ]);

        if ($visibleProjectIds !== null) {
            if ($visibleProjectIds === []) {
                return $builder->whereRaw('1 = 0');
            }

            $builder->whereIn('s.client_id', function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
                $sub->select('client_id')
                    ->from('projects')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $visibleProjectIds);
            });
        }

        return $builder;
    }

    /** A timestamp as the API publishes it, or null where the platform has never been read. */
    private function toIso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    }

    private function view(stdClass $row): StoreView
    {
        $provider = Provider::from((string) $row->provider);

        return new StoreView(
            id: (string) $row->id,
            clientId: (string) $row->client_id,
            clientName: (string) $row->client_name,
            provider: $provider,
            externalId: (string) $row->external_id,
            name: (string) $row->name,
            domain: is_string($row->domain) && $row->domain !== '' ? $row->domain : null,
            currency: (string) $row->currency,
            timezone: is_string($row->timezone) && $row->timezone !== '' ? $row->timezone : null,
            isActive: (bool) $row->is_active,
            lastSyncedAt: $this->toIso($row->last_synced_at),
            ordersSyncedThrough: $this->toIso($row->orders_synced_through),
            /*
             * Published rather than assumed. A store whose provider has no sync adapter can be registered —
             * its webhooks still arrive — and nothing can go and read it, which is a different state from
             * "never read" and the interface has to be able to say so.
             */
            isReadable: $this->adapters->supports($provider),
        );
    }
}
