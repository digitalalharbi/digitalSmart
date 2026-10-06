<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Integration\Contract\IntegrationReadModel;
use App\Application\Integration\Contract\ProviderAdapterRegistry;
use App\Application\Integration\DTO\ProviderView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;
use App\Domain\Integration\ProviderEntity;
use App\Domain\Integration\ProviderRateLimit;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use DateTimeInterface;

/**
 * Every provider and where the workspace stands with it.
 *
 * Lists all providers, not only connected ones: an unconnected platform with an
 * honest "not configured" status is more useful than an empty page, and it is
 * where someone goes to start a connection.
 */
final readonly class ListProviders
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private IntegrationReadModel $readModel,
        private ProviderAdapterRegistry $adapters,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<ProviderView> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        $workspaceId = $this->actor->workspaceId();
        $accountCounts = $this->readModel->accountCountsByProvider($workspaceId);

        $views = [];

        foreach (Provider::cases() as $provider) {
            $adapter = $this->adapters->for($provider);
            $connection = $this->connections->findForProvider($workspaceId, $provider);
            $credentials = $connection?->credentials();

            $missing = $credentials === null
                ? $provider->requiredCredentialKeys()
                : $credentials->missing($provider->requiredCredentialKeys());

            $status = $connection?->status() ?? \App\Domain\Integration\ConnectionStatus::NotConfigured;

            $views[] = new ProviderView(
                provider: $provider->value,
                label: $provider->label(),
                /*
                 * The registry's own answers. Published so no page has to keep its own map
                 * of what a provider is called or what kind of thing it is.
                 */
                category: $provider->category()->value,
                categoryLabel: $provider->category()->label(),
                apiStatus: $provider->apiStatus()->value,
                apiStatusLabel: $provider->apiStatus()->label(),
                authMethod: $provider->authMethod()->value,
                capabilities: array_map(
                    static fn (ProviderCapability $capability): array => [
                        'slug' => $capability->value,
                        'label' => $capability->label(),
                        'is_write' => $capability->isWrite(),
                    ],
                    // The adapter's answer, not the platform's brochure: this
                    // is what is actually implemented.
                    $adapter->capabilities(),
                ),
                entities: array_map(
                    static fn (ProviderEntity $entity): array => [
                        'slug' => $entity->value,
                        'label' => $entity->label(),
                    ],
                    $provider->supportedEntities(),
                ),
                rateLimit: $this->rateLimit($provider->rateLimit()),
                documentationUrl: $provider->documentationUrl(),
                connectionId: $connection?->id->value,
                status: $status->value,
                statusLabel: $status->label(),
                canSync: $status->canSync(),
                needsAttention: $status->needsAttention(),
                statusMessage: $connection?->statusMessage(),
                missingCredentials: $missing,
                // Surfaced so the interface can say *why* a provider is not
                // connected, rather than implying someone forgot to click.
                isBlockedExternal: $missing !== [] || $adapter->capabilities() === [],
                accountCount: $accountCounts[$provider->value] ?? 0,
                lastCheckedAt: $connection?->lastCheckedAt()?->format(DateTimeInterface::ATOM),
                expiresAt: $connection?->expiresAt()?->format(DateTimeInterface::ATOM),
            );
        }

        return $views;
    }

    /**
     * What the provider publishes about its own limits, or null.
     *
     * Null stays null: a provider that documents no ceiling must not appear to have one,
     * because a number nobody set is a number nobody can check.
     *
     * @return array<string, mixed>|null
     */
    private function rateLimit(?ProviderRateLimit $limit): ?array
    {
        if ($limit === null || ! $limit->isDocumented()) {
            return null;
        }

        return [
            'requests' => $limit->requests,
            'window_seconds' => $limit->windowSeconds,
            'scope' => $limit->scope,
            'daily_requests' => $limit->dailyRequests,
            'note' => $limit->note,
        ];
    }
}
