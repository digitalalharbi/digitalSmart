<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Application\Advertising\DTO\SyncContext;

/**
 * Persisting what a sync read.
 *
 * Every method is an upsert keyed on the provider's own identifier, so running
 * the same sync twice produces the same database. That is not a convenience:
 * jobs retry, ranges overlap deliberately because attribution keeps moving, and
 * a sync that double-counted spend would be worse than one that never ran.
 *
 * Writes must be atomic per batch. A half-written page leaves a day looking
 * cheaper than it was, which is a number someone will act on.
 */
interface AdvertisingStore
{
    /**
     * @param  list<CampaignRecord>  $records
     * @return int rows written
     */
    public function upsertCampaigns(SyncContext $context, array $records): int;

    /** @param list<AdGroupRecord> $records */
    public function upsertAdGroups(SyncContext $context, array $records): int;

    /** @param list<CreativeRecord> $records */
    public function upsertCreatives(SyncContext $context, array $records): int;

    /** @param list<AdRecord> $records */
    public function upsertAds(SyncContext $context, array $records): int;

    /**
     * Daily metrics and their conversion breakdown.
     *
     * Records whose entity is unknown to us are skipped rather than invented:
     * an insight row for an ad we have not synced yet has nowhere to attach,
     * and creating a placeholder entity would put a nameless row in a report.
     *
     * @param  list<InsightRecord>  $records
     */
    public function upsertInsights(SyncContext $context, array $records): int;
}
