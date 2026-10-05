<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\AdEntityType;
use DateTimeImmutable;

/**
 * One day of performance for one entity, as the adapter normalised it.
 *
 * Nulls are meaningful and must survive the whole pipeline: LinkedIn does not
 * expose deduplicated reach at every level, X has no link-click metric matching
 * Meta's, Google reports conversions as a float under its own attribution. A
 * null here says "not reported"; a zero says "reported, and it was none".
 */
final readonly class InsightRecord
{
    /**
     * @param  list<ConversionRecord>  $conversionBreakdown
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public AdEntityType $entityType,
        public string $externalEntityId,
        /** The account's own date, in the account's own timezone. */
        public DateTimeImmutable $date,
        public string $currency,
        public ?int $spendMinor = null,
        public ?int $revenueMinor = null,
        public ?int $impressions = null,
        public ?int $reach = null,
        public ?int $clicks = null,
        public ?int $linkClicks = null,
        public ?int $videoViews = null,
        public ?int $videoCompletions = null,
        public ?int $engagements = null,
        public ?float $conversions = null,
        public array $conversionBreakdown = [],
        public ?string $accountTimezone = null,
        public array $metadata = [],
    ) {}
}
