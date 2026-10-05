<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\SyncKind;
use App\Domain\Advertising\SyncStatus;

/** What one sync actually achieved. Reported, not inferred. */
final readonly class SyncOutcome
{
    /** @param array<string, int> $writtenByKind */
    public function __construct(
        public SyncStatus $status,
        public int $recordsRead = 0,
        public int $recordsWritten = 0,
        public int $apiCalls = 0,
        public array $writtenByKind = [],
        public ?string $failureMessage = null,
        /** The kind that failed, when one did. */
        public ?SyncKind $failedKind = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === SyncStatus::Succeeded;
    }
}
