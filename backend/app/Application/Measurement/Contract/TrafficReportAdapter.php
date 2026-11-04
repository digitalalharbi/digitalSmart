<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\TrafficPage;
use App\Domain\Integration\Credentials;
use DateTimeImmutable;

/**
 * Reading acquisition traffic — sessions, users and engagement by channel, source and medium.
 *
 * A second, optional capability beside `MeasurementSyncAdapter`: an analytics property can answer it, a tag container
 * and a store cannot. Implemented only by an adapter that really reads it, and the sync asks `instanceof` rather than
 * assuming — a stub that returned an empty page would read as "no traffic".
 *
 * Aggregate counts only. There is no user-level path in this contract and none can be built on it.
 */
interface TrafficReportAdapter
{
    public function fetchTraffic(
        Credentials $credentials,
        string $propertyId,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): TrafficPage;
}
