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
use App\Infrastructure\Advertising\Mapper\MetaMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use DateTimeImmutable;

/**
 * Meta Marketing API sync.
 *
 * Mirrors the real edges: `/{ad_account_id}/campaigns`, `/adsets`, `/ads`,
 * `/adcreatives` and `/insights`, each paginated by an opaque cursor, each
 * returning the node fields MetaMapper reads.
 *
 * BLOCKED_EXTERNAL: a live call needs a Business-type Meta app with Marketing
 * API access and `ads_read` approved through App Review. Until then this reads
 * SANDBOX fixtures and refuses outright for real credentials.
 */
final class MetaSyncAdapter extends AbstractSyncAdapter
{
    public function __construct(SandboxFixtures $fixtures, private readonly MetaMapper $mapper)
    {
        parent::__construct($fixtures);
    }

    public function provider(): Provider
    {
        return Provider::Meta;
    }

    /**
     * Meta's longest selectable click window.
     *
     * The account default is 7-day click plus 1-day view, but an ad set may be
     * configured for 28-day click, and a purchase attributed that way lands
     * against a date four weeks old. Re-reading less would silently lose it.
     */
    public function attributionLookbackDays(): int
    {
        return 28;
    }

    /**
     * Our batching window, not a provider ceiling.
     *
     * The Insights API will answer a 37-month range, but a wide synchronous
     * request is the reliable way to be rate-limited or time out. 92 days keeps
     * each request small enough to retry cheaply.
     */
    public function maxInsightRangeDays(): int
    {
        return 92;
    }

    /** Meta calls them ad sets, and the fixtures keep the provider's own word. */
    protected function fixtureName(string $kind): string
    {
        return match ($kind) {
            'ad_groups' => 'adsets',
            'creatives' => 'adcreatives',
            'insights_ad_group' => 'insights_adset',
            default => $kind,
        };
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
