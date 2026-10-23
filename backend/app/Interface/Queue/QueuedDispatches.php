<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Tracking\Contract\DispatchQueue;

final readonly class QueuedDispatches implements DispatchQueue
{
    public function push(string $dispatchId): void
    {
        DispatchProviderEventJob::dispatch($dispatchId)->afterCommit();
    }
}
