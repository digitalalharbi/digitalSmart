<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\ReportQuality;

/** A page of traffic rows, with what the provider said about its own completeness. */
final readonly class TrafficPage
{
    /** @param list<TrafficRecord> $items */
    public function __construct(
        public array $items,
        public ReportQuality $quality,
        public ?string $nextCursor = null,
        public ?string $timeZone = null,
    ) {}
}
