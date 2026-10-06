<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use App\Domain\Measurement\TrackingIssue;

/**
 * What a container's published state is, and what is wrong with it.
 *
 * Separate from `TrackingHealthView` because the two answers come from different places and must
 * be allowed to fail independently. Tracking health is derived from this product's own tables and
 * always answers; readiness requires a live call to Tag Manager, which can be slow, rate-limited
 * or unauthorised. Folding one into the other would mean a provider outage emptying the tracking
 * page of facts it already had.
 */
final readonly class ContainerReadinessView
{
    /**
     * @param  list<TrackingIssue>  $issues
     * @param  list<string>  $measurementIdsFound
     * @param  list<string>  $measurementIdsExpected
     */
    public function __construct(
        public DiscoveredContainer $container,
        public bool $hasLiveVersion,
        public ?string $liveVersionId,
        public bool $hasUnpublishedChanges,
        public int $publishedTagCount,
        public array $issues,
        /** Every id the published version mentions — the evidence behind the id findings. */
        public array $measurementIdsFound = [],
        /** The ids this client's analytics sources are known by. */
        public array $measurementIdsExpected = [],
    ) {}

    public function isReady(): bool
    {
        return $this->hasLiveVersion && $this->criticalCount() === 0;
    }

    public function criticalCount(): int
    {
        return count(array_filter(
            $this->issues,
            static fn (TrackingIssue $issue): bool => $issue->severity->rank() === 0,
        ));
    }
}
