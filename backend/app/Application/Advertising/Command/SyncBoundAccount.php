<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\DTO\SyncContext;
use App\Application\Advertising\DTO\SyncOutcome;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Integration\Repository\ExternalAccountRepository;
use App\Domain\Integration\Repository\IntegrationBindingRepository;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Sync one external account that a project has been given.
 *
 * This is where the isolation model is enforced for data, not just for reads:
 * an account nobody bound is never fetched, and an account whose bindings are
 * all paused is never fetched either. Discovering an account grants nothing, and
 * neither does connecting the platform it came from.
 *
 * It is also where a number's trustworthiness is decided, once. A connection
 * running on sandbox credentials produces rows stamped SANDBOX, and there is no
 * branch anywhere below that can relabel them — which is the whole reason the
 * decision is made here rather than at the point of display.
 */
final readonly class SyncBoundAccount
{
    public function __construct(
        private ExternalAccountRepository $accounts,
        private ProviderConnectionRepository $connections,
        private IntegrationBindingRepository $bindings,
        private SyncExternalAccount $sync,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $workspaceId,
        string $externalAccountId,
        ?DateTimeImmutable $since = null,
        ?DateTimeImmutable $until = null,
    ): SyncOutcome {
        $workspace = new WorkspaceId($workspaceId);
        $accountId = new ExternalAccountId($externalAccountId);

        $account = $this->accounts->findInWorkspace($workspace, $accountId);

        if ($account === null) {
            // Another workspace's account, or none at all. Same answer either
            // way: nothing here to sync, and nothing disclosed about why.
            return new SyncOutcome(SyncStatus::Skipped);
        }

        if ($this->bindings->syncEnabledCountForAccount($workspace, $accountId) === 0) {
            return new SyncOutcome(SyncStatus::Skipped);
        }

        $connection = $this->connections->findInWorkspace($workspace, $account->connectionId);

        if ($connection === null) {
            return new SyncOutcome(SyncStatus::Skipped);
        }

        $credentials = $connection->credentials();
        $isSandbox = $credentials->isSandbox($account->provider->requiredCredentialKeys());

        $context = new SyncContext(
            workspaceId: $workspaceId,
            externalAccountId: $externalAccountId,
            externalAccountExternalId: $account->externalId,
            provider: $account->provider,
            // An account with no reported currency cannot have its spend added
            // to anything, so there is no sensible default to invent here.
            currency: $account->currency() ?? 'USD',
            source: $isSandbox ? MetricSource::Sandbox : MetricSource::Synced,
            accountTimezone: $account->timezone(),
            providerConnectionId: $connection->id->value,
        );

        $outcome = ($this->sync)($context, $credentials, $since, $until);

        if ($outcome->recordsWritten > 0) {
            $this->bindings->touchSyncedAt($workspace, $accountId, $this->clock->now());
        }

        return $outcome;
    }
}
