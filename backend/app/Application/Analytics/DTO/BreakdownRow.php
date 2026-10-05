<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Analytics\Dimension;

/** One group in a breakdown — a platform, a campaign, an ad. */
final readonly class BreakdownRow
{
    public function __construct(
        public Dimension $dimension,
        /** The grouping key as stored: a uuid, a provider slug. */
        public string $key,
        public string $label,
        public MetricSet $metrics,
        /** Enough to drill down, where the dimension has children. */
        public ?string $parentKey = null,
        public ?string $externalId = null,
        public ?string $status = null,
    ) {}
}
