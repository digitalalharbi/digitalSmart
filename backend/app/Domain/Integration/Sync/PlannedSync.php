<?php

declare(strict_types=1);

namespace App\Domain\Integration\Sync;

final readonly class PlannedSync
{
    public function __construct(
        public SyncCandidate $candidate,
        public SyncClass $class,
        /** Seconds to hold the job back: the per-provider throttle, spread across the dispatch. */
        public int $delaySeconds,
    ) {}
}
