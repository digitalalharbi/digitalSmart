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
use App\Infrastructure\Advertising\Mapper\TikTokMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;

/**
 * TikTok Marketing API sync.
 *
 * Mirrors `/campaign/get/`, `/adgroup/get/`, `/ad/get/` and
 * `/report/integrated/get/`. TikTok paginates by **page number** rather than a
 * cursor, which the sandbox's opaque cursor stands in for — the caller never
 * interprets it either way, so swapping the transport changes nothing above.
 *
 * BLOCKED_EXTERNAL: a live call needs a TikTok for Business developer app with
 * Marketing API approval and an authorised advertiser.
 */
final class TikTokSyncAdapter extends AbstractSyncAdapter
{
    public function __construct(SandboxFixtures $fixtures, private readonly TikTokMapper $mapper)
    {
        parent::__construct($fixtures);
    }

    public function provider(): Provider
    {
        return Provider::TikTok;
    }

    /**
     * TikTok's default attribution is 7-day click plus 1-day view, with 28-day
     * click available per ad group. Re-reading the longest keeps a late purchase
     * from being lost against a day already synced.
     */
    public function attributionLookbackDays(): int
    {
        return 28;
    }

    /**
     * TikTok's own documented limit: a report broken down by `stat_time_day`
     * accepts a range of at most 30 days. Asking for more is an error, not a
     * slow request, so this is a hard ceiling rather than a batching choice.
     */
    public function maxInsightRangeDays(): int
    {
        return 30;
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

    protected function mapInsight(array $row, AdEntityType $level, string $currency): InsightRecord
    {
        return $this->mapper->insight($row, $level, $currency);
    }
}
