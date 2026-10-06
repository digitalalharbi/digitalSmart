<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\ReportQuality;

/**
 * One page of observations, with what the provider said about their completeness.
 *
 * The quality travels *with* the rows rather than being fetched separately, because that is
 * the only arrangement in which it cannot be dropped. A caller holding a page of counts has
 * the caveats in its hand; a caller that had to ask a second time would eventually forget to.
 */
final readonly class ObservedEventPage
{
    /** @param list<ObservedEventRecord> $items */
    public function __construct(
        public array $items,
        public ReportQuality $quality,
        public ?string $nextCursor = null,
        /** The property's reporting currency, as the provider stated it on this response. */
        public ?string $currencyCode = null,
        /** The property's time zone — the day boundary every date here was cut on. */
        public ?string $timeZone = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
