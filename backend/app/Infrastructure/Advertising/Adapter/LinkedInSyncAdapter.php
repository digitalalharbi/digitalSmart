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
use App\Infrastructure\Advertising\Mapper\LinkedInMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use DateTimeImmutable;

/**
 * LinkedIn Marketing API sync.
 *
 * Mirrors `/rest/adAccounts/{id}/adCampaignGroups`, `/adCampaigns`, `/creatives` and
 * `/rest/adAnalytics`. Every request carries a dated `LinkedIn-Version` header and
 * `X-Restli-Protocol-Version: 2.0.0`; versions sunset about a year after release, so
 * the header is a thing to keep current rather than set once.
 *
 * **The hierarchy does not line up with ours, and the names actively mislead.**
 * LinkedIn's structure is account → campaignGroup → campaign → creative, and its
 * *campaign* is what carries targeting, a bid, a budget and an objective — an ad set
 * by any other name. So:
 *
 *   our campaign  ← LinkedIn campaign group
 *   our ad group  ← LinkedIn campaign
 *   our ad        ← LinkedIn creative
 *
 * Anyone comparing our screen with Campaign Manager needs to know that, which is why
 * it is in docs/INTEGRATIONS.md, the mapper and the fixture README as well as here.
 *
 * Two more differences worth knowing before reading the mapper: money is a decimal
 * string in an `{amount, currencyCode}` object rather than micros, and schedules are
 * epoch milliseconds.
 *
 * Rate limits worth respecting once the transport is wired: the reporting endpoint
 * allows 45 million *metric values* per five-minute window — requested metrics times
 * returned rows — and answers 429 `TOO_MANY_REQUESTS` beyond it, which is why a sync
 * asks for the metrics it maps and no more. A request may name at most 20 metrics, and
 * only `impressions` and `clicks` come back when none are named. The retryable
 * conditions are 429, 500 `INTERNAL_ERROR` and 503 `UNDER_MAINTENANCE`.
 *
 * BLOCKED_EXTERNAL: a live call needs a LinkedIn app with Advertising API access
 * through their partner programme, plus `r_ads` and `r_ads_reporting`. Until then this
 * reads SANDBOX fixtures and refuses outright for real credentials.
 */
final class LinkedInSyncAdapter extends AbstractSyncAdapter
{
    public function __construct(SandboxFixtures $fixtures, private readonly LinkedInMapper $mapper)
    {
        parent::__construct($fixtures);
    }

    public function provider(): Provider
    {
        return Provider::LinkedIn;
    }

    /**
     * LinkedIn's conversion windows run to 30 days post-click and 7 post-view.
     *
     * A 30-day window means a conversion can still be attributed to a day a month
     * old, so the four-week default is the minimum defensible re-read and is kept.
     */
    public function attributionLookbackDays(): int
    {
        return 30;
    }

    /**
     * 92 days, because that is the ceiling on reach.
     *
     * `approximateMemberReach` is only returned for ranges of 92 days or less. A wider
     * request would still answer, silently without reach — so the window is the
     * provider's own limit rather than a batching preference, and asking for less
     * would cost requests for nothing.
     */
    public function maxInsightRangeDays(): int
    {
        return 92;
    }

    protected function fixtureName(string $kind): string
    {
        /*
         * LinkedIn's own vocabulary, so the files can be read against its
         * documentation — and so the mismatch with our levels stays visible rather
         * than being hidden behind a familiar file name.
         */
        return match ($kind) {
            'campaigns' => 'campaign_groups',
            'ad_groups' => 'campaigns',
            'ads', 'creatives' => 'creatives',
            default => $kind,
        };
    }

    protected function mapCampaign(array $row): CampaignRecord
    {
        return $this->mapper->campaign($row, $this->reportingCurrency());
    }

    protected function mapAdGroup(array $row): AdGroupRecord
    {
        return $this->mapper->adGroup($row, $this->reportingCurrency());
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
     * One row is one entity's day: LinkedIn's DAILY granularity returns an element per
     * day with `dateRange.start` equal to `dateRange.end`.
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
