<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Adapter;

use App\Application\Advertising\Contract\AdvertisingSyncAdapter;
use App\Application\Advertising\DTO\AdGroupRecord;
use App\Application\Advertising\DTO\AdRecord;
use App\Application\Advertising\DTO\CampaignRecord;
use App\Application\Advertising\DTO\CreativeRecord;
use App\Application\Advertising\DTO\InsightRecord;
use App\Application\Advertising\DTO\Page;
use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Infrastructure\Advertising\Sandbox\SandboxFixtures;
use DateTimeImmutable;

/**
 * Shared plumbing for sync adapters, and the gate that keeps them honest.
 *
 * Each concrete adapter implements the provider's *real* contract: real field
 * names, real pagination, real enum strings, real metric availability. What none
 * of them can do yet is make a live call, because no provider has issued
 * digitalSmart credentials. So the transport is the one thing stubbed, and it is
 * stubbed by refusal: with real credentials the adapter throws and names the
 * missing approval; with the literal credential `sandbox` it reads
 * provider-shaped fixtures off disk.
 *
 * The consequence is deliberate. There is no code path that quietly invents a
 * number for a live connection. Swapping fixtures for HTTP later replaces the
 * `rows()` source and leaves every mapping, every cursor and every test alone.
 *
 * Subclasses supply two things and inherit the rest: which fixture file answers
 * which request, and how to turn one of its rows into a normalised record.
 */
abstract class AbstractSyncAdapter implements AdvertisingSyncAdapter
{
    public function __construct(protected readonly SandboxFixtures $fixtures) {}

    abstract public function provider(): Provider;

    /**
     * How far back conversions keep landing against past dates.
     *
     * Defaults to a four-week re-read. Providers that attribute over a longer
     * window override it; none should shorten it without evidence, because the
     * cost of re-reading a day is one request and the cost of missing a late
     * conversion is a client seeing a worse ROAS than they earned.
     */
    public function attributionLookbackDays(): int
    {
        return 28;
    }

    public function maxInsightRangeDays(): int
    {
        return 92;
    }

    /** @return Page<CampaignRecord> */
    final public function fetchCampaigns(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $page = $this->rows($credentials, $this->fixtureName('campaigns'), $cursor);

        return new Page(
            items: $this->mapRows($page->items, 'campaigns', fn (array $row): CampaignRecord => $this->mapCampaign($row)),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<AdGroupRecord> */
    final public function fetchAdGroups(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $page = $this->rows($credentials, $this->fixtureName('ad_groups'), $cursor);

        return new Page(
            items: $this->mapRows($page->items, 'ad_groups', fn (array $row): AdGroupRecord => $this->mapAdGroup($row)),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<AdRecord> */
    final public function fetchAds(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $page = $this->rows($credentials, $this->fixtureName('ads'), $cursor);

        return new Page(
            items: $this->mapRows($page->items, 'ads', fn (array $row): AdRecord => $this->mapAd($row)),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<CreativeRecord> */
    final public function fetchCreatives(Credentials $credentials, string $externalAccountId, ?string $cursor = null): Page
    {
        $page = $this->rows($credentials, $this->fixtureName('creatives'), $cursor);

        return new Page(
            items: $this->mapRows($page->items, 'creatives', fn (array $row): CreativeRecord => $this->mapCreative($row)),
            nextCursor: $page->nextCursor,
        );
    }

    /** @return Page<InsightRecord> */
    final public function fetchInsights(
        Credentials $credentials,
        string $externalAccountId,
        AdEntityType $level,
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        ?string $cursor = null,
    ): Page {
        /*
         * One request per level, as against the real APIs: asking for a
         * different level changes which ids come back and recomputes reach, so a
         * parent's row is not the sum of its children's.
         */
        $page = $this->rows(
            $credentials,
            $this->fixtureName('insights_'.$level->value),
            $cursor,
            $this->withinRange($since, $until, $this->insightDateField()),
        );

        $records = [];

        foreach ($page->items as $row) {
            $entity = $this->unwrap($row, 'insights');

            if ($entity === null) {
                continue;
            }

            foreach ($this->mapInsights($entity, $level, $this->reportingCurrency(), $since) as $record) {
                $records[] = $record;
            }
        }

        return new Page(items: $records, nextCursor: $page->nextCursor);
    }

    /**
     * The fixture file answering a request.
     *
     * Overridden where a provider's own vocabulary differs — Meta's ad sets are
     * `adsets`, not `ad_groups` — so the file names stay recognisable to anyone
     * comparing them with the provider's documentation.
     */
    protected function fixtureName(string $kind): string
    {
        return $kind;
    }

    /**
     * Pull the entity out of a provider's response envelope.
     *
     * Most providers return the entity directly. Snapchat wraps each item as
     * `{sub_request_status, campaign: {…}}`, because a batch can partly succeed — so
     * unwrapping is a provider concern, not a mapper one, and a row whose request
     * failed carries no entity at all.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null null drops the row
     */
    protected function unwrap(array $row, string $kind): ?array
    {
        return $row;
    }

    /** @param array<string, mixed> $row */
    abstract protected function mapCampaign(array $row): CampaignRecord;

    /** @param array<string, mixed> $row */
    abstract protected function mapAdGroup(array $row): AdGroupRecord;

    /** @param array<string, mixed> $row */
    abstract protected function mapAd(array $row): AdRecord;

    /** @param array<string, mixed> $row */
    abstract protected function mapCreative(array $row): CreativeRecord;

    /**
     * The records one raw insight row stands for — usually one.
     *
     * A list rather than a single record, because providers disagree about what a row
     * is. Meta, Google, TikTok and Snapchat return one row per entity per day. X
     * returns a *time series*: one array per metric, aligned to the requested range,
     * so one of its rows is a whole range. `$since` is the start the request asked
     * for, which is what a series without dates is aligned to.
     *
     * @param  array<string, mixed>  $row
     * @return list<InsightRecord>
     */
    abstract protected function mapInsights(
        array $row,
        AdEntityType $level,
        string $currency,
        DateTimeImmutable $since,
    ): array;

    /**
     * Which key in an insight row holds its date.
     *
     * Meta puts it at the top level as `date_start`; Google nests it under
     * `segments.date`, which the loader flattens for range filtering only.
     */
    protected function insightDateField(): string
    {
        return 'date_start';
    }

    /**
     * The sandbox account's reporting currency.
     *
     * A fallback only: every mapper prefers the currency on the row itself,
     * because that is where a live response states it.
     */
    protected function reportingCurrency(): string
    {
        return 'SAR';
    }

    /** True when this connection is wired to fixtures rather than an endpoint. */
    protected function isSandbox(Credentials $credentials): bool
    {
        return $credentials->isSandbox($this->provider()->requiredCredentialKeys());
    }

    /**
     * One page of raw provider rows — the only door into a fetch.
     *
     * @param  callable(array<string, mixed>): bool|null  $filter
     * @return Page<array<string, mixed>>
     *
     * @throws ProviderNotConfigured when credentials are absent, or present but
     *                               not yet usable against the live API
     */
    protected function rows(
        Credentials $credentials,
        string $fixture,
        ?string $cursor,
        ?callable $filter = null,
    ): Page {
        $this->assertSandbox($credentials);

        return $this->fixtures->page($this->provider(), $fixture, $cursor, $filter);
    }

    /**
     * @throws ProviderNotConfigured
     */
    protected function assertSandbox(Credentials $credentials): void
    {
        $missing = $credentials->missing($this->provider()->requiredCredentialKeys());

        if ($missing !== []) {
            throw ProviderNotConfigured::for($this->provider(), $missing);
        }

        if (! $this->isSandbox($credentials)) {
            throw ProviderNotConfigured::awaitingProviderApproval($this->provider());
        }
    }

    /**
     * Inclusive day-level range filter, applied to the provider's own date field.
     *
     * @return callable(array<string, mixed>): bool
     */
    protected function withinRange(
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        string $field = 'date_start',
    ): callable {
        $from = $since->format('Y-m-d');
        $to = $until->format('Y-m-d');

        return static function (array $row) use ($field, $from, $to): bool {
            $value = $row[$field] ?? null;

            return is_string($value) && $value >= $from && $value <= $to;
        };
    }

    /**
     * Unwrap, map, and drop the rows that carried no entity.
     *
     * @template TRecord
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): TRecord  $map
     * @return list<TRecord>
     */
    private function mapRows(array $rows, string $kind, callable $map): array
    {
        $records = [];

        foreach ($rows as $row) {
            $entity = $this->unwrap($row, $kind);

            if ($entity !== null) {
                $records[] = $map($entity);
            }
        }

        return $records;
    }
}
