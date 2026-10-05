<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\Contract\AdvertisingStore;
use App\Application\Advertising\Contract\AdvertisingSyncAdapter;
use App\Application\Advertising\Contract\SyncAdapterRegistry;
use App\Application\Advertising\Contract\SyncJournal;
use App\Application\Advertising\DTO\Page;
use App\Application\Advertising\DTO\SyncContext;
use App\Application\Advertising\DTO\SyncOutcome;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\ProviderErrorCategory;
use App\Domain\Advertising\SyncKind;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\Credentials;
use DateTimeImmutable;

/**
 * Reads one external ad account and writes what it finds.
 *
 * The order is not arbitrary. Campaigns before ad groups before ads, because an
 * ad references both its ad group and its creative, and an insight row can only
 * be attached to an entity we already know. A run that fetched performance first
 * would discard most of it.
 *
 * Failure is partial by design. If ads sync but performance does not, the ads
 * are kept and the run is marked `partial` — the alternative is throwing away
 * good data because a later request was rate-limited. What is never acceptable
 * is a run that reports success while missing days, which is why the outcome
 * carries the status and every written row carries its source.
 */
final readonly class SyncExternalAccount
{
    public function __construct(
        private SyncAdapterRegistry $adapters,
        private AdvertisingStore $store,
        private SyncJournal $journal,
        private Clock $clock,
    ) {}

    /**
     * @param  DateTimeImmutable|null  $since  start of the performance window;
     *                                         defaults to the adapter's attribution lookback
     */
    public function __invoke(
        SyncContext $context,
        Credentials $credentials,
        ?DateTimeImmutable $since = null,
        ?DateTimeImmutable $until = null,
    ): SyncOutcome {
        if (! $this->adapters->supports($context->provider)) {
            // Not a failure: there is nothing to read, and saying so is the
            // honest result. The UI needs this to be distinguishable from an error.
            return new SyncOutcome(SyncStatus::Skipped);
        }

        $adapter = $this->adapters->for($context->provider);

        $read = 0;
        $written = 0;
        $calls = 0;
        $byKind = [];
        $failures = [];

        $account = $context->externalAccountExternalId;

        /*
         * Dependency order, and not cosmetic: an ad references both an ad group
         * and a creative, so it is read last or its links cannot be resolved.
         */
        $passes = [
            $this->syncStructure(
                $context,
                SyncKind::Campaigns,
                fn (?string $cursor): Page => $adapter->fetchCampaigns($credentials, $account, $cursor),
                fn (array $records): int => $this->store->upsertCampaigns($context, $records),
            ),
            $this->syncStructure(
                $context,
                SyncKind::AdGroups,
                fn (?string $cursor): Page => $adapter->fetchAdGroups($credentials, $account, $cursor),
                fn (array $records): int => $this->store->upsertAdGroups($context, $records),
            ),
            $this->syncStructure(
                $context,
                SyncKind::Creatives,
                fn (?string $cursor): Page => $adapter->fetchCreatives($credentials, $account, $cursor),
                fn (array $records): int => $this->store->upsertCreatives($context, $records),
            ),
            $this->syncStructure(
                $context,
                SyncKind::Ads,
                fn (?string $cursor): Page => $adapter->fetchAds($credentials, $account, $cursor),
                fn (array $records): int => $this->store->upsertAds($context, $records),
            ),
        ];

        foreach ($passes as $pass) {
            $read += $pass['read'];
            $written += $pass['written'];
            $calls += $pass['calls'];
            $byKind[$pass['kind']->value] = $pass['written'];

            if ($pass['failure'] !== null) {
                $failures[$pass['kind']->value] = $pass['failure'];
            }
        }

        $performance = $this->syncInsights($adapter, $context, $credentials, $since, $until);

        $read += $performance['read'];
        $written += $performance['written'];
        $calls += $performance['calls'];
        $byKind[SyncKind::Insights->value] = $performance['written'];

        if ($performance['failure'] !== null) {
            $failures[SyncKind::Insights->value] = $performance['failure'];
        }

        if ($failures === []) {
            return new SyncOutcome(SyncStatus::Succeeded, $read, $written, $calls, $byKind);
        }

        $failedKind = SyncKind::from((string) array_key_first($failures));

        return new SyncOutcome(
            // Something landed, so the data is real but incomplete. Nothing
            // landed, so the run failed outright.
            status: $written > 0 ? SyncStatus::Partial : SyncStatus::Failed,
            recordsRead: $read,
            recordsWritten: $written,
            apiCalls: $calls,
            writtenByKind: $byKind,
            failureMessage: (string) reset($failures),
            failedKind: $failedKind,
        );
    }

    /**
     * Page through one structure kind until the provider stops offering cursors.
     *
     * The cursor is persisted after every successful page, so a run interrupted
     * at page nine resumes at page nine rather than page one.
     *
     * Reading and writing arrive as a pair of callables rather than a match on
     * the kind, which keeps the paging loop in one place and lets the record
     * type flow from the adapter's page straight into the store's signature.
     *
     * @template TRecord
     *
     * @param  callable(?string): Page<TRecord>  $fetch
     * @param  callable(list<TRecord>): int  $write
     * @return array{kind: SyncKind, read: int, written: int, calls: int, failure: ?string}
     */
    private function syncStructure(
        SyncContext $context,
        SyncKind $kind,
        callable $fetch,
        callable $write,
    ): array {
        $runId = $this->journal->begin($context, $kind);
        $cursor = $this->journal->cursorFor($context, $kind);
        $read = 0;
        $written = 0;
        $calls = 0;

        try {
            do {
                $page = $fetch($cursor);

                $calls += $page->apiCalls;
                $read += count($page->items);
                $written += $write($page->items);

                $cursor = $page->nextCursor;
                $this->journal->rememberCursor($context, $kind, $cursor);
            } while ($cursor !== null);

            $this->journal->complete($runId, new SyncOutcome(SyncStatus::Succeeded, $read, $written, $calls));

            return ['kind' => $kind, 'read' => $read, 'written' => $written, 'calls' => $calls, 'failure' => null];
        } catch (ProviderRequestFailed|ProviderNotConfigured|ProviderUnavailable $e) {
            $this->journal->recordError(
                $runId,
                $context,
                $this->categorise($e),
                $e->getMessage(),
                $e instanceof ProviderRequestFailed ? $e->statusCode : null,
                $e instanceof ProviderRequestFailed && $e->isRetryable(),
                $e instanceof ProviderRequestFailed ? $e->retryAfterSeconds : null,
            );
            $this->journal->complete($runId, new SyncOutcome(
                $written > 0 ? SyncStatus::Partial : SyncStatus::Failed,
                $read,
                $written,
                $calls,
                failureMessage: $e->getMessage(),
            ));
            $this->journal->recordFailure($context, $kind);

            return ['kind' => $kind, 'read' => $read, 'written' => $written, 'calls' => $calls, 'failure' => $e->getMessage()];
        }
    }

    /**
     * Read performance for every level the provider reports separately.
     *
     * The window always reaches back by the adapter's attribution lookback, even
     * when a watermark says those days are done. They are not done: a 28-day
     * click window means a purchase can still be attributed to a day four weeks
     * ago, and trusting the watermark would freeze that day at a worse ROAS than
     * the client actually earned.
     *
     * @return array{read: int, written: int, calls: int, failure: ?string}
     */
    private function syncInsights(
        AdvertisingSyncAdapter $adapter,
        SyncContext $context,
        Credentials $credentials,
        ?DateTimeImmutable $since,
        ?DateTimeImmutable $until,
    ): array {
        $today = $this->clock->now();
        $until ??= $today;
        $lookback = $adapter->attributionLookbackDays();

        if ($since === null) {
            $watermark = $this->journal->watermarkFor($context, SyncKind::Insights);
            $resyncFrom = $watermark['resyncFrom'];

            $since = $resyncFrom ?? $until->modify('-'.$lookback.' days');
        }

        // Never ask for more than the adapter says it can answer in one go.
        $maxDays = $adapter->maxInsightRangeDays();

        if ($since < $until->modify('-'.$maxDays.' days')) {
            $since = $until->modify('-'.$maxDays.' days');
        }

        $runId = $this->journal->begin($context, SyncKind::Insights, $since, $until);
        $read = 0;
        $written = 0;
        $calls = 0;

        try {
            foreach ([AdEntityType::Campaign, AdEntityType::AdGroup, AdEntityType::Ad] as $level) {
                $cursor = null;

                do {
                    $page = $adapter->fetchInsights(
                        $credentials,
                        $context->externalAccountExternalId,
                        $level,
                        $since,
                        $until,
                        $cursor,
                    );

                    $calls += $page->apiCalls;
                    $read += count($page->items);
                    $written += $this->store->upsertInsights($context, $page->items);

                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
            }

            $this->journal->rememberWatermark(
                $context,
                SyncKind::Insights,
                $until,
                // Next run re-reads this far back, for the same reason this one did.
                $until->modify('-'.$lookback.' days'),
            );
            $this->journal->complete($runId, new SyncOutcome(SyncStatus::Succeeded, $read, $written, $calls));

            return ['read' => $read, 'written' => $written, 'calls' => $calls, 'failure' => null];
        } catch (ProviderRequestFailed|ProviderNotConfigured|ProviderUnavailable $e) {
            $this->journal->recordError(
                $runId,
                $context,
                $this->categorise($e),
                $e->getMessage(),
                $e instanceof ProviderRequestFailed ? $e->statusCode : null,
                $e instanceof ProviderRequestFailed && $e->isRetryable(),
                $e instanceof ProviderRequestFailed ? $e->retryAfterSeconds : null,
            );
            $this->journal->complete($runId, new SyncOutcome(
                $written > 0 ? SyncStatus::Partial : SyncStatus::Failed,
                $read,
                $written,
                $calls,
                failureMessage: $e->getMessage(),
            ));
            $this->journal->recordFailure($context, SyncKind::Insights);

            return ['read' => $read, 'written' => $written, 'calls' => $calls, 'failure' => $e->getMessage()];
        }
    }

    private function categorise(ProviderRequestFailed|ProviderNotConfigured|ProviderUnavailable $e): ProviderErrorCategory
    {
        return match (true) {
            $e instanceof ProviderNotConfigured => ProviderErrorCategory::Auth,
            $e instanceof ProviderUnavailable => ProviderErrorCategory::Permission,
            default => ProviderErrorCategory::fromStatusCode($e->statusCode),
        };
    }
}
