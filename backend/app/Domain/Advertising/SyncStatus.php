<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    /** Some pages landed, some did not. The data is real but incomplete. */
    case Partial = 'partial';
    case Failed = 'failed';
    /** Nothing to do — no binding, or the provider has no sync adapter. */
    case Skipped = 'skipped';

    public function isFinished(): bool
    {
        return $this !== self::Pending && $this !== self::Running;
    }

    /**
     * Whether data from this run may be shown without a freshness warning.
     *
     * A partial run is the interesting case: its rows are real, but totals
     * built from them understate reality, so they carry MetricSource::Partial.
     */
    public function isTrustworthy(): bool
    {
        return $this === self::Succeeded;
    }
}
