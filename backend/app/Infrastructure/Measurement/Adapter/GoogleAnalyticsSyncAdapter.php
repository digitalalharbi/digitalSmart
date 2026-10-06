<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement\Adapter;

use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Measurement\Contract\MeasurementSyncAdapter;
use App\Application\Measurement\DTO\DataStreamRecord;
use App\Application\Measurement\DTO\DiscoveredProperty;
use App\Application\Measurement\DTO\KeyEventRecord;
use App\Application\Measurement\DTO\ObservedEventPage;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Infrastructure\Measurement\Mapper\GoogleAnalyticsMapper;
use App\Infrastructure\Measurement\Sandbox\MeasurementFixtures;
use DateTimeImmutable;

/**
 * Google Analytics 4, read through the Admin and Data APIs.
 *
 * Built against the published contracts:
 *
 *  - `GET https://analyticsadmin.googleapis.com/v1beta/accountSummaries` — accounts with their
 *    `propertySummaries[]`. Pages with `pageSize` (default 50, max 200) and `pageToken`.
 *  - `GET …/v1beta/properties/{id}/dataStreams` — the streams, so a person can recognise the
 *    property by its site or its `G-` measurement id rather than by a number.
 *  - `GET …/v1beta/properties/{id}/keyEvents` — what the property's owner marked as key.
 *  - `GET https://analyticsdata.googleapis.com/v1beta/properties/{id}/metadata` — which
 *    dimensions and metrics this property actually has.
 *  - `POST https://analyticsdata.googleapis.com/v1beta/properties/{id}:runReport` — the counts.
 *
 * Read-only, permanently. Both APIs can write, and this product will not: changing somebody's
 * measurement configuration from a reporting tool is not a feature, it is a way to break a
 * setup nobody can then explain. The registry records that as no write capability at all.
 *
 * **BLOCKED_EXTERNAL.** The live calls need a Google Cloud project with both APIs enabled, an
 * OAuth client whose redirect URI is allow-listed, and a user who can read the property. There
 * is no programme to be admitted to — the blocker is configuration and consent — so the
 * transport is the one thing stubbed, and it is stubbed by refusal: real credentials throw and
 * name what is missing, the literal credential `sandbox` reads response-shaped fixtures. There
 * is no path that invents a number for a live connection.
 *
 * Quota, for whoever wires the transport: a standard property allows 200,000 core tokens per
 * day, 40,000 per hour, 14,000 per project per property per hour, and 10 concurrent requests.
 * Exhaustion is `429 RESOURCE_EXHAUSTED` and should be retried with exponential backoff; `500
 * INTERNAL` and `503 UNAVAILABLE` likewise, mindful that 10 server errors per project per
 * property per hour blocks the pair outright.
 */
final readonly class GoogleAnalyticsSyncAdapter implements MeasurementSyncAdapter
{
    /** Fixture names, each holding what the matching endpoint returns. */
    private const string FIXTURE_ACCOUNTS = 'account_summaries';

    private const string FIXTURE_STREAMS = 'data_streams';

    private const string FIXTURE_KEY_EVENTS = 'key_events';

    private const string FIXTURE_METADATA = 'metadata';

    private const string FIXTURE_REPORT = 'report_event_counts';

    public function __construct(
        private MeasurementFixtures $fixtures,
        private GoogleAnalyticsMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::GoogleAnalytics;
    }

    /** @return list<DiscoveredProperty> */
    public function discoverProperties(Credentials $credentials): array
    {
        $this->assertConfigured($credentials);

        $streams = $this->streamsByProperty();
        $properties = [];

        foreach ($this->fixtures->all($this->provider(), self::FIXTURE_ACCOUNTS) as $summary) {
            /*
             * One account summary can carry many properties, and an agency's account carries
             * the ones for clients it no longer works with too. All of them are returned; which
             * belongs to which client is a commercial decision a person makes.
             */
            foreach ($this->mapper->properties($summary, $streams) as $property) {
                $properties[] = $property;
            }
        }

        return $properties;
    }

    /** @return list<KeyEventRecord> */
    public function fetchKeyEvents(Credentials $credentials, string $propertyId): array
    {
        $this->assertConfigured($credentials);

        $events = [];

        foreach ($this->fixtures->all($this->provider(), self::FIXTURE_KEY_EVENTS) as $row) {
            $event = $this->mapper->keyEvent($row);

            if ($event->eventName !== '') {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function fetchEventCounts(
        Credentials $credentials,
        string $propertyId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): ObservedEventPage {
        $this->assertConfigured($credentials);

        /*
         * The live request this stands in for:
         *
         *   dimensions: [{name: 'date'}, {name: 'eventName'}]
         *   metrics:    [{name: 'eventCount'}, … the property's currency metric, if it has one]
         *   dateRanges: [{startDate: 'Y-m-d', endDate: 'Y-m-d'}]
         *   limit / offset for paging, keepEmptyRows omitted — a name with no events on a day is
         *   not a row worth storing, and asking for them multiplies the response by the size of
         *   the event vocabulary for no new information.
         */
        $valueMetric = $this->valueMetricFor($credentials, $propertyId);
        $page = $this->fixtures->page($this->provider(), self::FIXTURE_REPORT, $cursor);
        $response = $page['response'];

        $currency = $this->mapper->currencyOf($response);

        $records = [];

        foreach ($this->mapper->observedEvents($response, $valueMetric, $currency) as $record) {
            // The range filter the real request would have applied server-side.
            if ($record->date >= $since->format('Y-m-d') && $record->date <= $until->format('Y-m-d')) {
                $records[] = $record;
            }
        }

        return new ObservedEventPage(
            items: $records,
            /*
             * What the provider said about its own answer, carried with the rows. A sampled or
             * thresholded response produces counts that are not totals, and this is what lets
             * the caller label them `PARTIAL` instead of presenting an estimate as a fact.
             */
            quality: $this->mapper->quality($response),
            nextCursor: $page['nextCursor'],
            currencyCode: $currency,
            timeZone: $this->mapper->timeZoneOf($response),
        );
    }

    public function valueMetricFor(Credentials $credentials, string $propertyId): ?string
    {
        $this->assertConfigured($credentials);

        /*
         * Asked, never assumed. Which metrics a property has is a property-level fact: custom
         * metrics differ between properties, and a property with no revenue data blocks its
         * currency metrics outright with `NO_REVENUE_METRICS`. Requesting one anyway turns every
         * sync into a 400, and hard-coding a name we had not verified would be inventing a
         * metric.
         */
        return $this->mapper->valueMetric($this->fixtures->first($this->provider(), self::FIXTURE_METADATA));
    }

    /**
     * The Data API answers much longer ranges than this in one request.
     *
     * Kept at a quarter because a year of daily rows per event name is a large response to hold
     * in memory for no benefit: the sync writes day rows either way, and a shorter window means
     * a failure costs one chunk rather than the whole history.
     */
    public function maxRangeDays(): int
    {
        return 92;
    }

    /**
     * How far back every sync re-reads.
     *
     * Analytics figures keep moving after the day closes: late hits, modelled conversions
     * filling in, and reprocessing all change a figure that already looked final. Two days
     * would miss most of it; a fortnight costs a handful of requests and means a client is not
     * shown a worse week than they had.
     */
    public function restatementWindowDays(): int
    {
        return 14;
    }

    /**
     * Streams for every property in the fixtures, keyed by property resource name.
     *
     * A real sync makes one call per property. The fixture holds them together and they are
     * grouped by the `name` field's own prefix — `properties/123456/dataStreams/789` — which is
     * how the live response identifies its parent too.
     *
     * @return array<string, list<DataStreamRecord>>
     */
    private function streamsByProperty(): array
    {
        $grouped = [];

        foreach ($this->fixtures->all($this->provider(), self::FIXTURE_STREAMS) as $row) {
            $stream = $this->mapper->dataStream($row);
            $parent = $this->parentOf($stream->streamId);

            if ($parent !== null) {
                $grouped[$parent][] = $stream;
            }
        }

        return $grouped;
    }

    /** `properties/123456/dataStreams/789` → `properties/123456`. */
    private function parentOf(string $streamName): ?string
    {
        if (preg_match('#^(properties/[^/]+)/dataStreams/#', $streamName, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @throws ProviderNotConfigured when credentials are missing, or real and therefore unusable
     */
    private function assertConfigured(Credentials $credentials): void
    {
        $required = $this->provider()->requiredCredentialKeys();
        $missing = $credentials->missing($required);

        if ($missing !== []) {
            throw ProviderNotConfigured::for($this->provider(), $missing);
        }

        if (! $credentials->isSandbox($required)) {
            /*
             * Real credentials, but no authorised OAuth client to use them with. Failing loudly
             * is the point: a silent fake success would put invented event counts in front of a
             * client, and the message says plainly that nothing the operator entered is wrong.
             */
            throw ProviderNotConfigured::awaitingProviderApproval($this->provider());
        }
    }
}
