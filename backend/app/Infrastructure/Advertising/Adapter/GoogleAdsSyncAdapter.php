<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Adapter;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Mapper\GoogleAdsMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use DateTimeImmutable;

/**
 * Google Ads API sync.
 *
 * Where Meta has an edge per entity, Google has one endpoint —
 * `customers/{id}/googleAds:searchStream` — answering a GAQL query, and the
 * query decides which resources appear in each `GoogleAdsRow`. The fixtures are
 * one file per query we would issue, which is the honest mapping of that model.
 *
 * BLOCKED_EXTERNAL: a live call needs a Google Cloud OAuth client **and** a
 * developer token approved for production by Google. Basic access is rate-capped
 * and will not sustain daily syncing, so the approval matters as much as the
 * token.
 */
final class GoogleAdsSyncAdapter extends AbstractSyncAdapter
{
    public function __construct(SandboxFixtures $fixtures, private readonly GoogleAdsMapper $mapper)
    {
        parent::__construct($fixtures);
    }

    public function provider(): Provider
    {
        return Provider::GoogleAds;
    }

    /**
     * Google's default conversion window is 30 days from the click, and a
     * conversion action may be configured for up to 90. Re-reading 30 days
     * catches the default; anything longer is a per-action setting we cannot
     * know without reading the conversion actions themselves.
     */
    public function attributionLookbackDays(): int
    {
        return 30;
    }

    /**
     * Google will answer a far wider range, but `searchStream` holds the
     * connection open for the whole result set, so a narrower window fails
     * faster and retries cheaper.
     */
    public function maxInsightRangeDays(): int
    {
        return 90;
    }

    /** Google nests the day under `segments`, as the real response does. */
    protected function insightDateField(): string
    {
        return 'date_start';
    }

    protected function mapCampaign(array $row): CampaignRecord
    {
        return $this->mapper->campaign($row);
    }

    protected function mapAdGroup(array $row): AdGroupRecord
    {
        return $this->mapper->adGroup($row);
    }

    protected function mapAd(array $row): AdRecord
    {
        return $this->mapper->ad($row);
    }

    protected function mapCreative(array $row): CreativeRecord
    {
        return $this->mapper->creative($row);
    }

    /**
     * One row is one entity's day, so always exactly one record.
     *
     * @return list<InsightRecord>
     */
    protected function mapInsights(
        array $row,
        AdEntityType $level,
        string $currency,
        DateTimeImmutable $since,
    ): array {
        return [$this->mapper->insight($row, $level, $currency)];
    }
}
