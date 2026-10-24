<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Campaign\Contract\LaunchQueue;

final readonly class QueuedLaunches implements LaunchQueue
{
    public function push(string $workspaceId, string $executionId, string $executedBy): void
    {
        CreateCampaignJob::dispatch($workspaceId, $executionId, $executedBy)->afterCommit();
    }

    public function pushActivation(string $workspaceId, string $executionId, string $approvedBy): void
    {
        ActivateCampaignJob::dispatch($workspaceId, $executionId, $approvedBy)->afterCommit();
    }
}
