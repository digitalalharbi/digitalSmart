<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

interface LaunchQueue
{
    /** Hand one account's creation to a worker. Never run in the request: a launch across platforms is always queued. */
    public function push(string $workspaceId, string $executionId, string $executedBy): void;
}
