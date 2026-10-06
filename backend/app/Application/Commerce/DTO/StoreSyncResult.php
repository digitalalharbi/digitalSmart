<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Advertising\SyncStatus;

/** What one store sync actually achieved. Reported, not inferred. */
final readonly class StoreSyncResult
{
    public function __construct(
        public SyncStatus $status,
        public int $ordersWritten = 0,
        public int $productsWritten = 0,
        public int $customersWritten = 0,
        public int $apiCalls = 0,
        public MetricSource $source = MetricSource::Synced,
        public ?string $failureMessage = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === SyncStatus::Succeeded;
    }
}
