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
use App\Infrastructure\Advertising\Mapper\SnapchatMapper;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use DateTimeImmutable;

/**
 * Snapchat Marketing API sync.
 *
 * Mirrors `/adaccounts/{id}/campaigns`, `/adsquads`, `/ads`, `/creatives` and the
 * stats endpoints. Two shapes differ from every other provider here:
 *
 *   - Each item is wrapped as `{sub_request_status, <entity>: {…}}`, because a batch
 *     can partly succeed. A row whose own request failed carries no entity, so it is
 *     dropped rather than mapped into something empty.
 *   - Pagination is a whole `paging.next_link` URL rather than a token, so the opaque
 *     cursor the caller stores is that URL.
 *
 * BLOCKED_EXTERNAL: a live call needs a Snap Business app with Marketing API access
 * and an authorised ad account. Until then this reads SANDBOX fixtures and refuses
 * outright for real credentials.
 */
final class SnapchatSyncAdapter extends AbstractSyncAdapter
{
    public function __construct(SandboxFixtures $fixtures, private readonly SnapchatMapper $mapper)
    {
        parent::__construct($fixtures);
    }

    public function provider(): Provider
    {
        return Provider::Snapchat;
    }

    /**
     * Snapchat's longest selectable swipe-up attribution window is 28 days.
     *
     * An ad squad may be set to one, seven or twenty-eight days, and a purchase
     * attributed on the longest lands against a date four weeks old. Re-reading less
     * would lose it.
     */
    public function attributionLookbackDays(): int
    {
        return 28;
    }

    /**
     * Our batching window rather than a published ceiling.
     *
     * Snapchat's stats endpoints accept a wide range, but a day-granularity request
     * over months is the reliable way to time out. A quarter keeps each request small
     * enough to retry cheaply.
     */
    public function maxInsightRangeDays(): int
    {
        return 92;
    }

    /**
     * Pull the entity out of Snapchat's double-wrapped list item.
     *
     * A row whose `sub_request_status` is not a success carries no entity, so it is
     * dropped. Mapping it anyway would produce a record with an empty id, which the
     * store would then happily write.
     */
    protected function unwrap(array $row, string $kind): ?array
    {
        // Insight rows are flattened by the caller and arrive unwrapped.
        if ($kind === 'insights') {
            return $row;
        }

        $status = $row['sub_request_status'] ?? null;

        if (is_string($status) && strtoupper($status) !== 'SUCCESS') {
            return null;
        }

        $key = match ($kind) {
            'campaigns' => 'campaign',
            'ad_groups' => 'adsquad',
            'ads' => 'ad',
            'creatives' => 'creative',
            default => null,
        };

        $entity = $key === null ? null : $row[$key] ?? null;

        /** @var array<string, mixed>|null */
        return is_array($entity) ? $entity : null;
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
