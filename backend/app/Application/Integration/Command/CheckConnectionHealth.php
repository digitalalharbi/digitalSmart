<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Integration\Contract\ProviderAdapterRegistry;
use App\Application\Integration\DTO\ProviderHealth;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;

/**
 * Re-check a connection and record what came back.
 *
 * The adapter's health check never throws, so one unreachable provider cannot
 * break a page that lists all of them.
 */
final readonly class CheckConnectionHealth
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private ProviderAdapterRegistry $adapters,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    public function __invoke(Provider $provider): ProviderHealth
    {
        $this->guard->ensure(Permission::WorkspacesView);

        $workspaceId = $this->actor->workspaceId();
        $connection = $this->connections->findForProvider($workspaceId, $provider)
            ?? throw ResourceNotFound::of('Provider connection');

        $health = $this->adapters->for($provider)->checkHealth($connection->credentials());
        $now = $this->clock->now();

        if ($health->healthy) {
            $connection->recordHealthy($now);
        } else {
            $connection->markFailed($health->status, $health->message ?? 'Unknown error.', $now);
        }

        $this->connections->save($connection);

        return $health;
    }
}
