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
use App\Infrastructure\Advertising\Mapper\XAdsMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use DateTimeImmutable;

/**
 * X Ads API v12 sync.
 *
 * Mirrors `/12/accounts/:id/campaigns`, `/line_items`, `/promoted_tweets`,
 * `/cards/all` and `/12/stats/accounts/:id`. Four things are X's own:
 *
 *   - **Auth is OAuth 1.0a**, three-legged, signing every request with a consumer
 *     key and secret plus a per-user access token and secret. It is the only
 *     provider here that is not OAuth 2.0, which is why `Provider::X` declares
 *     `AuthMethod::OAuth1` and asks for `consumer_key`/`consumer_secret`. There is no
 *     refresh token to rotate: an OAuth 1.0a access token stays valid until the user
 *     revokes it, so a reconnection is a user action rather than a scheduled job.
 *   - **Stats are a time series.** One request returns, per entity, one array per
 *     metric aligned to the requested range — so a row is a range, not a day, and is
 *     expanded here rather than mapped one to one.
 *   - **Objectives live on the line item**, so a campaign record carries none.
 *   - **Reach is a separate endpoint** with its own limits, so stats rows report no
 *     reach at all rather than something standing in for it.
 *
 * Rate limits worth respecting when the transport is wired: synchronous stats are
 * 250 requests per 15 minutes per user, and the asynchronous jobs endpoint allows
 * 100 concurrent jobs per account. A 429 arrives as `TOO_MANY_REQUESTS`, and
 * `OVER_CAPACITY` (503), `INTERNAL_ERROR` (500), `CANCELLED_REQUEST` (408) and
 * `LOCK_ACQUISITION_TIMEOUT` (423) are the documented retryable conditions.
 *
 * BLOCKED_EXTERNAL: a live call needs an X developer app with Ads API access
 * granted through X's access form, plus a user who can act on the ads account.
 * Until then this reads SANDBOX fixtures and refuses outright for real credentials.
 */
final class XAdsSyncAdapter extends AbstractSyncAdapter
{
    public function __construct(SandboxFixtures $fixtures, private readonly XAdsMapper $mapper)
    {
        parent::__construct($fixtures);
    }

    public function provider(): Provider
    {
        return Provider::X;
    }

    /**
     * X finalises most metrics after 24 hours and spend within three days.
     *
     * Its conversion windows are configured per web event tag rather than published
     * as a single ceiling, so the four-week default stands: re-reading a day costs one
     * request, and missing a late conversion costs a client a worse ROAS than they
     * earned.
     */
    public function attributionLookbackDays(): int
    {
        return 28;
    }

    /**
     * Seven days, which is X's own ceiling for the synchronous stats endpoint.
     *
     * Not a batching preference like the other providers': asking for more is refused
     * by the API. Longer ranges need the asynchronous jobs endpoint — 90 days
     * unsegmented — which returns a downloadable file and is a different transport,
     * not a different parameter.
     */
    public function maxInsightRangeDays(): int
    {
        return 7;
    }

    protected function fixtureName(string $kind): string
    {
        /*
         * X's own vocabulary, so the files can be compared with its documentation:
         * ad groups are line items and ads are promoted tweets. Insight files keep the
         * shared names because they are keyed by our entity levels.
         */
        return match ($kind) {
            'ad_groups' => 'line_items',
            'ads' => 'promoted_tweets',
            'creatives' => 'cards',
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
     * One stats row is a whole range on X, so it expands into one record per bucket.
     *
     * The series carries its length, not its dates: bucket *i* is the requested start
     * plus *i* days. In the sandbox the fixture loader stamps each row with the day it
     * stands for, so a one-bucket row — exactly what a single-day request returns —
     * lands on that day; a live response has no per-row date and starts where the
     * request said it did.
     *
     * @return list<InsightRecord>
     */
    protected function mapInsights(
        array $row,
        AdEntityType $level,
        string $currency,
        DateTimeImmutable $since,
    ): array {
        $stamped = $row['date_start'] ?? null;

        return $this->mapper->insights(
            $row,
            $level,
            $currency,
            is_string($stamped) ? new DateTimeImmutable($stamped) : $since,
        );
    }
}
