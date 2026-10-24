<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Campaign\Launch\ExecuteCreation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One ad account's creation. **Tried once by the queue**: a create that timed out may have created something, so the queue never
 * repeats it — an unknown outcome is recorded and reconciled by a person, and only a proven failure is retried, by a person.
 */
final class CreateCampaignJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $executionId,
        public readonly string $executedBy,
    ) {}

    public function uniqueId(): string
    {
        return $this->executionId;
    }

    public function handle(ExecuteCreation $execute): void
    {
        $execute($this->workspaceId, $this->executionId, $this->executedBy);
    }
}
