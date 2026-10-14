<?php

declare(strict_types=1);

namespace App\Application\Measurement\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Integration\Exception\ProviderRequestFailed;
use App\Application\Integration\Exception\ProviderUnavailable;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\Contract\MeasurementSyncAdapterRegistry;
use App\Application\Measurement\Contract\ObservedEventWriter;
use App\Application\Measurement\DTO\MeasurementSyncResult;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Shared\ValueObject\DateWindow;
use DateTimeImmutable;

/**
 * Read one measurement source and write what it reported.
 *
 * Three decisions in here are worth more than the plumbing around them.
 *
 * **It re-reads a window instead of resuming from a high-water mark.** Analytics figures keep
 * moving after the day closes — late hits, modelled conversions filling in, reprocessing — so a
 * sync that only fetched "since last time" would freeze every day at its first, lowest value.
 * The window is the adapter's, and the writer upserts, so re-reading costs a few requests and
 * corrects the past instead of fossilising it.
 *
 * **The weakest label in the run wins.** If any page came back sampled or thresholded, every row
 * this run wrote is `PARTIAL`. Labelling page by page would be more precise and less useful: a
 * chart totalling a complete day and an estimated one is an estimate, and a reader cannot see
 * which rows contributed.
 *
 * **A failure part-way through keeps what arrived.** The days already written are real and stay,
 * the run is `partial`, and the message says what stopped. Throwing away good data because a
 * later request was rate-limited would be worse. What is never acceptable is a run reporting
 * success while missing days — which is why the status is carried out of here rather than
 * assumed by the caller.
 */
final readonly class SyncMeasurementSource
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private MeasurementSyncAdapterRegistry $adapters,
        private ObservedEventWriter $writer,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(string $sourceId, ?DateTimeImmutable $since = null): MeasurementSyncResult
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $source = $this->sources->find($workspaceId, $this->visibility->visibleProjectIds(), $sourceId);

        if ($source === null) {
            throw ResourceNotFound::of('Measurement source');
        }

        if ($source->provider === null || ! $this->adapters->supports($source->provider)) {
            /*
             * Not a failure. A manual source or a CRM has nothing to read, and a provider with no
             * adapter yet is a gap in this product rather than a problem with the client's setup.
             * The interface needs this distinguishable from an error so it can disable the button
             * instead of offering one that fails.
             */
            return new MeasurementSyncResult(SyncStatus::Skipped);
        }

        $adapter = $this->adapters->for($source->provider);
        $credentials = $this->credentialsFor($source->provider);

        $now = $this->clock->now();
        $until = $now;
        $from = $since ?? $now->modify('-'.max(1, $adapter->restatementWindowDays() - 1).' days');

        $rows = 0;
        $calls = 0;
        $days = [];
        $caveats = [];
        $weakest = MetricSource::Synced;
        $lastEventDate = null;
        $failure = null;

        try {
            /*
             * Windowed by the limit the adapter itself publishes — 92 days for the GA4 Data API, kept there
             * because a year of daily rows per event name is a large response to hold for no benefit. Before
             * this the range went out whole, so a long first sync asked for more than the adapter said it would
             * answer: the limit was recorded and ignored, which is worse than not knowing it, because the
             * adapter looked as though it had been honoured.
             *
             * A failure now costs one window rather than the whole history, which is the other half of the
             * reason the limit exists.
             */
            foreach (DateWindow::split($from, $until, $adapter->maxRangeDays()) as $window) {
                $cursor = null;

                do {
                    $page = $adapter->fetchEventCounts(
                        $credentials,
                        $source->externalId,
                        $window->from,
                        $window->until,
                        $cursor,
                    );
                    $calls++;

                    $isSandbox = $credentials->isSandbox($source->provider->requiredCredentialKeys());
                    $pageSource = $page->quality->metricSource($isSandbox);

                    /*
                     * `weakest()` is the honesty vocabulary's own comparison, so "sandbox beats
                     * partial beats synced" is decided in one place rather than re-argued here.
                     */
                    $weakest = MetricSource::weakest([$weakest, $pageSource]);

                    foreach ($page->quality->caveats() as $caveat) {
                        // Deduplicated: the same sampling note on nine pages is one fact.
                        $caveats[$caveat] = true;
                    }

                    foreach ($page->items as $record) {
                        $days[$record->date] = true;

                        if ($lastEventDate === null || $record->date > $lastEventDate) {
                            $lastEventDate = $record->date;
                        }
                    }

                    $rows += $this->writer->upsert($workspaceId, $source->id, $page->items, $pageSource);

                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
            }
        } catch (ProviderNotConfigured|ProviderRequestFailed|ProviderUnavailable $exception) {
            /*
             * Caught narrowly — only the three the provider layer raises. A programming error has
             * no business being recorded as a sync outcome, and swallowing one here is how a
             * tracking page comes to say "partial" for a year.
             */
            $failure = $exception->getMessage();
        }

        /*
         * Written even on a partial run. "We tried and got this far" is the fact a tracking page
         * needs; leaving the timestamp untouched would make a source that is failing
         * indistinguishable from one nobody has asked about.
         */
        $this->writer->markSynced(
            $workspaceId,
            $source->id,
            $now,
            $lastEventDate === null ? null : new DateTimeImmutable($lastEventDate.' 23:59:59'),
        );

        return new MeasurementSyncResult(
            status: $failure === null
                ? SyncStatus::Succeeded
                // Partial rather than failed when days did land: those figures are real.
                : ($rows > 0 ? SyncStatus::Partial : SyncStatus::Failed),
            daysRead: count($days),
            rowsWritten: $rows,
            apiCalls: $calls,
            source: $weakest,
            caveats: array_keys($caveats),
            failureMessage: $failure,
            lastEventDate: $lastEventDate,
        );
    }

    /**
     * The decrypted credentials for this source's connection.
     *
     * A source names its connection, so two clients measured through two different Google
     * accounts stay separate. Without one there is nothing to authenticate with, and saying so
     * names the thing to fix rather than failing inside the adapter on an empty token.
     */
    private function credentialsFor(Provider $provider): Credentials
    {
        $connection = $this->connections->agencyConnection($this->actor->workspaceId(), $provider);

        if ($connection === null) {
            /*
             * Nothing to authenticate with. Raised rather than returned as "skipped", because a
             * source registered against a provider nobody has connected is a setup somebody has
             * half finished, and the message names the credential to supply.
             */
            throw ProviderNotConfigured::for($provider, $provider->requiredCredentialKeys());
        }

        return $connection->credentials();
    }
}
