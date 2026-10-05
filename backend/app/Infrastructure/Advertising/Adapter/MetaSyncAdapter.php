<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Adapter;

use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Application\Advertising\DTO\Page;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Credentials;
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

    /** @return Page<CampaignRecord> */
    public function fetchCampaigns(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $this->assertSandbox($credentials);

        $page = $this->fixtures->page($this->provider(), 'campaigns', $cursor);

        return new Page(
            items: array_map(fn (array $row): CampaignRecord => $this->mapper->campaign($row), $page->items),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<AdGroupRecord> */
    public function fetchAdGroups(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $this->assertSandbox($credentials);

        $page = $this->fixtures->page($this->provider(), 'adsets', $cursor);

        return new Page(
            items: array_map(fn (array $row): AdGroupRecord => $this->mapper->adGroup($row), $page->items),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<AdRecord> */
    public function fetchAds(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $this->assertSandbox($credentials);

        $page = $this->fixtures->page($this->provider(), 'ads', $cursor);

        return new Page(
            items: array_map(fn (array $row): AdRecord => $this->mapper->ad($row), $page->items),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<CreativeRecord> */
    public function fetchCreatives(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $this->assertSandbox($credentials);

        $page = $this->fixtures->page($this->provider(), 'adcreatives', $cursor);

        return new Page(
            items: array_map(fn (array $row): CreativeRecord => $this->mapper->creative($row), $page->items),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<InsightRecord> */
    public function fetchInsights(
        Credentials $credentials,
        string $externalAccountId,
        AdEntityType $level,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): Page {
        $this->assertSandbox($credentials);

        /*
         * Each level is its own request, as it is against the real API: Meta's
         * `level` parameter changes which ids come back and recomputes reach,
         * so a parent's row is not the sum of its children's.
         */
        $fixture = 'insights_'.match ($level) {
            AdEntityType::Account => 'account',
            AdEntityType::Campaign => 'campaign',
            AdEntityType::AdGroup => 'adset',
            AdEntityType::Ad => 'ad',
        };

        $page = $this->fixtures->page(
            $this->provider(),
            $fixture,
            $cursor,
            $this->withinRange($since, $until),
        );

        // The account's reporting currency. Sandbox accounts are SAR; a live
        // adapter reads it from the row's own `account_currency`.
        $currency = 'SAR';

        return new Page(
            items: array_map(
                fn (array $row): InsightRecord => $this->mapper->insight($row, $level, $currency),
                $page->items,
            ),
            nextCursor: $page->nextCursor,
        );
    }
}
