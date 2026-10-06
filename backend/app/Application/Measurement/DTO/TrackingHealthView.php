<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\TrackingIssue;
use App\Domain\Measurement\TrackingSeverity;

/** Everything the tracking centre shows for one scope. */
final readonly class TrackingHealthView
{
    /**
     * @param  list<MeasurementSourceView>  $sources
     * @param  list<EventDefinitionView>  $definitions
     * @param  list<TrackingIssue>  $issues  worst first
     */
    public function __construct(
        public array $sources,
        public array $definitions,
        public array $issues,
        /** The window the observed counts were read over, published so a zero is readable. */
        public int $windowDays,
    ) {}

    /** Whether anything needs doing. */
    public function isHealthy(): bool
    {
        return $this->issues === [];
    }

    public function criticalCount(): int
    {
        return count(array_filter(
            $this->issues,
            static fn (TrackingIssue $issue): bool => $issue->severity === TrackingSeverity::Critical,
        ));
    }
}
