<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\DiscoveredProperty;
use App\Application\Measurement\DTO\KeyEventRecord;
use App\Application\Measurement\DTO\ObservedEventPage;
use App\Domain\Integration\Credentials;
use DateTimeImmutable;

/**
 * Reading measurement — what a property is, what it counts, and what it saw.
 *
 * Separate from `AdvertisingSyncAdapter` because the questions are different ones. An ad
 * platform is asked about campaigns and spend; an analytics property is asked which raw events
 * arrived. Forcing both through one interface would mean a GA4 adapter implementing
 * `fetchCampaigns()` as a shrug, and the first reader would reasonably assume it worked.
 *
 * Contract every implementation must honour:
 *
 *   - Return normalised records. No provider-shaped array crosses this boundary.
 *   - Keep provider ids verbatim, including the resource prefix (`properties/123456`).
 *   - **Never map a raw event name to a meaning.** That is the conversion registry's job, and
 *     it is a person's decision. An adapter reports that `purchase_complete` arrived 31 times.
 *   - **Report what the provider said about its own completeness.** A sampled or thresholded
 *     response produces figures that are not totals, and the caller must be able to label them.
 *   - Report null for a value the provider did not send. Never zero.
 *   - Be re-runnable over the same dates without double-counting.
 */
interface MeasurementSyncAdapter
{
    /**
     * Every property this connection can read.
     *
     * Not filtered to anything: the person choosing which property measures which client needs
     * to see what the account actually holds, including the properties that turn out to be
     * somebody else's.
     *
     * @return list<DiscoveredProperty>
     */
    public function discoverProperties(Credentials $credentials): array;

    /**
     * The events the property's owner marked as key events.
     *
     * Candidates for the conversion registry, never entries in it.
     *
     * @return list<KeyEventRecord>
     */
    public function fetchKeyEvents(Credentials $credentials, string $propertyId): array;

    /**
     * Daily counts per raw event name over an inclusive range.
     *
     * One row per name per day. The caller upserts them, so a re-read of the same range
     * replaces rather than accumulates.
     */
    public function fetchEventCounts(
        Credentials $credentials,
        string $propertyId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): ObservedEventPage;

    /**
     * The provider's own name for the metric that holds money on this property, if it has one.
     *
     * Asked rather than assumed. A property's available metrics are a property-level fact —
     * custom metrics differ per property, and some are blocked outright when the property has
     * no revenue data — so the only honest source for "which metric is the money one" is the
     * provider's metadata. Null means this property reports no currency metric, and a value
     * read from it would be invented.
     */
    public function valueMetricFor(Credentials $credentials, string $propertyId): ?string;

    /** Largest range the provider will answer in one request. */
    public function maxRangeDays(): int;

    /**
     * How far back to re-read on every sync.
     *
     * Analytics figures keep moving after the fact: late-arriving hits, modelled data filling
     * in, and reprocessing all change a day that already looked final.
     */
    public function restatementWindowDays(): int;
}
