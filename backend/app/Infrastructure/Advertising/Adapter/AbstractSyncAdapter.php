<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Adapter;

use App\Application\Advertising\Contract\AdvertisingSyncAdapter;
use App\Application\Integration\Exception\ProviderNotConfigured;
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
 * number for a live connection. Swapping fixtures for HTTP later changes the
 * `fetch*` bodies and nothing above them.
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

    /** True when this connection is wired to fixtures rather than an endpoint. */
    protected function isSandbox(Credentials $credentials): bool
    {
        return $credentials->isSandbox($this->provider()->requiredCredentialKeys());
    }

    /**
     * The only door into a fetch.
     *
     * @throws ProviderNotConfigured when credentials are absent, or present but
     *                               not yet usable against the live API
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
}
