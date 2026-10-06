<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Application\Shared\DTO\ProviderPage;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Credentials;
use DateTimeImmutable;

/**
 * Reading structure and performance from a provider.
 *
 * Separate from ProviderAdapter, which handles connection and discovery: a
 * provider can be connected long before any sync is implemented, and the health
 * of a connection should not depend on the sync surface.
 *
 * Contract every implementation must honour:
 *
 *   - Return normalised records. No provider-shaped type crosses this boundary.
 *   - Preserve provider ids and raw status strings; the caller needs both.
 *   - Report null for metrics the provider does not expose. Never substitute
 *     zero: "not reported" and "none" are different facts.
 *   - Paginate with an opaque cursor the caller stores and hands back.
 *   - Raise ProviderRequestFailed with retry guidance on rate limits, rather
 *     than sleeping inside the adapter and blocking a worker.
 *   - Be re-runnable for the same date range without duplicating anything.
 */
interface AdvertisingSyncAdapter
{
    /** @return ProviderPage<CampaignRecord> */
    public function fetchCampaigns(Credentials $credentials, string $externalAccountId, ?string $cursor = null): ProviderPage;

    /** @return ProviderPage<AdGroupRecord> */
    public function fetchAdGroups(Credentials $credentials, string $externalAccountId, ?string $cursor = null): ProviderPage;

    /** @return ProviderPage<AdRecord> */
    public function fetchAds(Credentials $credentials, string $externalAccountId, ?string $cursor = null): ProviderPage;

    /** @return ProviderPage<CreativeRecord> */
    public function fetchCreatives(Credentials $credentials, string $externalAccountId, ?string $cursor = null): ProviderPage;

    /**
     * Daily performance for one level over an inclusive date range.
     *
     * Levels are fetched separately rather than derived, because a parent's
     * figures are not the sum of its children: reach is deduplicated and
     * attribution moves conversions between levels.
     *
     * @return ProviderPage<InsightRecord>
     */
    public function fetchInsights(
        Credentials $credentials,
        string $externalAccountId,
        AdEntityType $level,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): ProviderPage;

    /**
     * How far back conversions keep landing against past dates.
     *
     * Meta attributes a purchase to the day of the click, so a 7-day window
     * means last week's numbers are still moving. Sync re-reads this many days
     * rather than trusting a high-water mark.
     */
    public function attributionLookbackDays(): int;

    /** Largest range the provider will answer in one request. */
    public function maxInsightRangeDays(): int;
}
