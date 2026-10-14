<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Advertising\Contract\SyncAdapterRegistry as AdvertisingRegistry;
use App\Application\Commerce\Contract\CommerceSyncAdapterRegistry;
use App\Application\Integration\Contract\ProviderAdapterRegistry;
use App\Application\Integration\DTO\CommissioningReadout;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry;
use App\Application\Measurement\Contract\TagContainerReadAdapter;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCapability;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Ask one provider what it can actually be made to do, and report only what came back.
 *
 * Commissioning an integration is a different activity from using one. It is done from a shell by whoever
 * holds the credentials, against one workspace, and its whole value is that it **reads and reports rather
 * than writes**: nothing here touches this product's tables, so a readout against a client's live account
 * cannot leave a figure behind that somebody later mistakes for a sync.
 *
 * Three rules it exists to enforce:
 *
 * **A sandbox credential can never produce a live verdict.** The fixtures are in this repository, so a run
 * against them says `SANDBOX_VERIFIED` whatever it reads — see `CommissioningReadout::level()`.
 *
 * **A field that came back null is reported as null.** The point of the exercise is to learn which metrics a
 * real account actually exposes; a readout that filled a gap would destroy the only evidence being gathered.
 *
 * **A provider failure is a finding, not an exception.** "Google refused this developer token" is the most
 * useful sentence a commissioning run can produce, and swallowing it into a stack trace would lose it.
 */
final readonly class ReadCommissioningState
{
    /** Small on purpose: this is a probe, not a sync, and a provider's rate limit is somebody's live account. */
    private const int PROBE_DAYS = 7;

    public function __construct(
        private ProviderConnectionRepository $connections,
        private ProviderAdapterRegistry $providers,
        private AdvertisingRegistry $advertising,
        private MeasurementSyncAdapterRegistry $measurement,
        private CommerceSyncAdapterRegistry $commerce,
        private TagContainerReadAdapter $containers,
    ) {}

    public function __invoke(Provider $provider, WorkspaceId $workspaceId): CommissioningReadout
    {
        $required = $provider->requiredCredentialKeys();
        $connection = $this->connections->findForProvider($workspaceId, $provider);

        if ($connection === null) {
            return new CommissioningReadout(
                provider: $provider,
                isConfigured: false,
                isSandbox: false,
                requiredKeys: $required,
                limitations: ['No connection in this workspace. Nothing has been attempted.'],
            );
        }

        $credentials = $connection->credentials();
        $isSandbox = $credentials->isSandbox($required);
        $missing = $credentials->missing($required);

        if ($missing !== []) {
            return new CommissioningReadout(
                provider: $provider,
                isConfigured: false,
                isSandbox: false,
                requiredKeys: $required,
                limitations: [sprintf('Credentials incomplete: %s not set.', implode(', ', $missing))],
            );
        }

        $health = $this->providers->for($provider)->checkHealth($credentials);
        $findings = [];
        $limitations = [];
        $failure = null;

        try {
            [$findings, $limitations] = $this->probe($provider, $credentials);
        } catch (ProviderNotConfigured|ProviderUnavailable|ProviderRequestFailed $exception) {
            /* The provider's own words. This is the sentence the ledger needs. */
            $failure = $exception->getMessage();
        }

        $capabilities = array_map(
            static fn (ProviderCapability $capability): string => $capability->value,
            $this->providers->for($provider)->capabilities(),
        );

        $writes = array_values(array_intersect($capabilities, [
            ProviderCapability::CampaignStatusMutation->value,
            ProviderCapability::BudgetMutation->value,
        ]));

        $findings[] = $writes === []
            ? 'Writes: none declared by the adapter.'
            : sprintf('Writes declared: %s. Unverified until a mutation is read back.', implode(', ', $writes));

        return new CommissioningReadout(
            provider: $provider,
            isConfigured: true,
            isSandbox: $isSandbox,
            requiredKeys: $required,
            health: $health,
            findings: $findings,
            limitations: $limitations,
            failure: $failure,
        );
    }

    /**
     * One bounded read per category, reporting what came back and what did not.
     *
     * @return array{list<string>, list<string>}
     */
    private function probe(Provider $provider, Credentials $credentials): array
    {
        return match ($provider->category()) {
            ProviderCategory::Advertising => $this->probeAdvertising($provider, $credentials),
            ProviderCategory::Analytics => $this->probeAnalytics($provider, $credentials),
            ProviderCategory::TagManagement => $this->probeContainers($credentials),
            ProviderCategory::Commerce => $this->probeCommerce($provider, $credentials),
            ProviderCategory::Crm => [[], ['CRM commissioning is driven from the lead module; see docs/CRM.md.']],
        };
    }

    /** @return array{list<string>, list<string>} */
    private function probeAdvertising(Provider $provider, Credentials $credentials): array
    {
        $accounts = $this->providers->for($provider)->discoverAccounts($credentials);

        $findings = [sprintf('Accounts discovered: %d.', count($accounts))];

        foreach (array_slice($accounts, 0, 5) as $account) {
            $findings[] = sprintf(
                '  %s — %s, currency %s, timezone %s.',
                $account->externalId,
                $account->name,
                $account->currency ?? 'not reported',
                $account->timezone ?? 'not reported',
            );
        }

        if ($accounts === [] || ! $this->advertising->supports($provider)) {
            return [$findings, ['No account to read insights from, or no sync adapter for this provider.']];
        }

        $adapter = $this->advertising->for($provider);
        $until = new DateTimeImmutable('yesterday');
        $since = $until->modify('-'.(self::PROBE_DAYS - 1).' days');
        $account = $accounts[0];

        $page = $adapter->fetchInsights(
            $credentials,
            $account->externalId,
            AdEntityType::Campaign,
            $since,
            $until,
        );

        $findings[] = sprintf(
            'Campaign insights %s → %s: %d row(s) in %d API call(s)%s.',
            $since->format('Y-m-d'),
            $until->format('Y-m-d'),
            count($page->items),
            $page->apiCalls,
            $page->nextCursor === null ? '' : ', more pages available',
        );

        if ($page->items === []) {
            return [$findings, ['No rows in the probe window: nothing to check metric coverage against.']];
        }

        /*
         * Which metrics this account actually exposes, counted rather than asserted. A provider that reports
         * no video views for a search campaign is correct; what matters is that the readout says so instead of
         * leaving somebody to infer it from a zero.
         */
        $fields = ['spendMinor', 'impressions', 'clicks', 'linkClicks', 'conversions', 'revenueMinor', 'videoViews'];
        $populated = [];

        foreach ($fields as $field) {
            $count = 0;

            foreach ($page->items as $row) {
                if ($row->{$field} !== null) {
                    $count++;
                }
            }

            $populated[] = sprintf('%s %d/%d', $field, $count, count($page->items));
        }

        $findings[] = '  Metric coverage: '.implode(', ', $populated).'.';
        $findings[] = sprintf('  Currency on the rows: %s.', $page->items[0]->currency);
        $findings[] = sprintf('  Account timezone: %s.', $page->items[0]->accountTimezone ?? 'not reported');
        $findings[] = sprintf(
            '  Adapter windows: %d-day attribution lookback, %d-day maximum range.',
            $adapter->attributionLookbackDays(),
            $adapter->maxInsightRangeDays(),
        );

        return [$findings, []];
    }

    /** @return array{list<string>, list<string>} */
    private function probeAnalytics(Provider $provider, Credentials $credentials): array
    {
        if (! $this->measurement->supports($provider)) {
            return [[], ['No measurement adapter for this provider.']];
        }

        $adapter = $this->measurement->for($provider);
        $properties = $adapter->discoverProperties($credentials);
        $findings = [sprintf('Properties discovered: %d.', count($properties))];

        foreach (array_slice($properties, 0, 5) as $property) {
            $findings[] = sprintf('  %s — %s.', $property->propertyId, $property->displayName);
        }

        if ($properties === []) {
            return [$findings, ['No property to read events from.']];
        }

        $propertyId = $properties[0]->propertyId;
        $keyEvents = $adapter->fetchKeyEvents($credentials, $propertyId);

        $findings[] = sprintf('Key events on %s: %d.', $propertyId, count($keyEvents));
        $findings[] = sprintf(
            'Revenue metric the property exposes: %s.',
            $adapter->valueMetricFor($credentials, $propertyId) ?? 'none reported',
        );
        $findings[] = sprintf(
            'Adapter windows: %d-day maximum range, %d-day restatement window.',
            $adapter->maxRangeDays(),
            $adapter->restatementWindowDays(),
        );

        return [$findings, ['Event counts are read by the sync; this probe reads the property and its vocabulary only.']];
    }

    /** @return array{list<string>, list<string>} */
    private function probeContainers(Credentials $credentials): array
    {
        $containers = $this->containers->discoverContainers($credentials);
        $findings = [sprintf('Containers discovered: %d.', count($containers))];

        foreach (array_slice($containers, 0, 5) as $container) {
            $findings[] = sprintf(
                '  %s — %s%s.',
                $container->publicId,
                $container->name,
                $container->isWeb() ? '' : ' (not a web container)',
            );
        }

        if ($containers === []) {
            return [$findings, ['No container to read a published version from.']];
        }

        $facts = $this->containers->readPublishedState($credentials, $containers[0]);

        $findings[] = sprintf(
            'Published state of %s: %s, %d tag(s)%s.',
            $containers[0]->publicId,
            $facts->hasLiveVersion ? 'live version '.($facts->liveVersionId ?? '?') : 'never published',
            count($facts->tags),
            $facts->hasUnpublishedChanges ? ', with unpublished changes' : '',
        );
        $findings[] = sprintf(
            '  Measurement ids found in it: %s.',
            $facts->measurementIdsFound === [] ? 'none' : implode(', ', $facts->measurementIdsFound),
        );

        return [$findings, []];
    }

    /** @return array{list<string>, list<string>} */
    private function probeCommerce(Provider $provider, Credentials $credentials): array
    {
        if (! $this->commerce->supports($provider)) {
            return [[], ['No commerce adapter for this provider.']];
        }

        $adapter = $this->commerce->for($provider);
        $stores = $adapter->discoverStores($credentials);
        $findings = [sprintf('Stores discovered: %d.', count($stores))];

        foreach (array_slice($stores, 0, 5) as $store) {
            $findings[] = sprintf(
                '  %s — %s, currency %s, timezone %s.',
                $store->externalId,
                $store->name,
                $store->currency,
                $store->timezone ?? 'not reported',
            );
        }

        if ($stores === []) {
            return [$findings, ['No store to read orders from.']];
        }

        $until = new DateTimeImmutable('now');
        $since = $until->modify('-'.(self::PROBE_DAYS - 1).' days');
        $page = $adapter->fetchOrders($credentials, $stores[0]->externalId, $since, $until);

        $findings[] = sprintf(
            'Orders %s → %s: %d row(s) in %d API call(s)%s.',
            $since->format('Y-m-d'),
            $until->format('Y-m-d'),
            count($page->items),
            $page->apiCalls,
            $page->nextCursor === null ? '' : ', more pages available',
        );
        $findings[] = sprintf(
            '  Adapter windows: %d-day restatement window, %d-day maximum range.',
            $adapter->restatementWindowDays(),
            $adapter->maxRangeDays(),
        );

        return [$findings, ['Order totals must still be compared with the merchant admin by a person.']];
    }
}
