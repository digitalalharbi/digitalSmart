<?php

declare(strict_types=1);

namespace App\Interface\Queue;

use App\Application\Export\Command\RunExport;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One background export, run as the person who asked.
 *
 * Bound as the requester so the rendering use case applies *their* visibility — a job that ran as "the system"
 * would be a side door around every project scope the interface enforces.
 */
final class RunExportJob implements ShouldQueue
{
    use Queueable;

    /** One try: the use case records its own failure, and a retry of a deterministic failure repeats it. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly string $exportId,
        private readonly string $workspaceId,
        private readonly string $userId,
    ) {
        $this->onQueue('reports');
    }

    public function handle(RunExport $run, ActorContextBinder $actors): void
    {
        $actors->bind(new UserId($this->userId), new WorkspaceId($this->workspaceId));

        try {
            $run($this->exportId);
        } finally {
            $actors->clear();
        }
    }

    public function failed(Throwable $exception): void
    {
        app(RunExport::class)->fail($this->exportId);
    }
}
