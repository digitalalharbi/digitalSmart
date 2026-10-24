<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Campaign\Launch\ExecuteActivation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One account's activation. Tried once: whether to try again is a person's decision, after reading what happened. */
final class ActivateCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $executionId,
        public readonly string $approvedBy,
    ) {}

    public function uniqueId(): string
    {
        return 'activate:'.$this->executionId;
    }

    public function handle(ExecuteActivation $execute): void
    {
        $execute($this->workspaceId, $this->executionId, $this->approvedBy);
    }
}
