<?php

declare(strict_types=1);

namespace App\Application\Export\Command;

use App\Application\Export\Contract\ExportFileStorage;
use App\Application\Export\Contract\ExportJobStore;
use App\Application\Export\Contract\ExportRenderer;
use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Shared\Contract\Clock;
use App\Domain\Export\ExportStatus;
use App\Domain\Notifications\NotificationKind;
use Throwable;

/**
 * Does the work of one export, as the person who asked.
 *
 * The caller has already bound the actor to the requester. A failure is recorded on the job with a message the
 * requester can read, and is **not** re-thrown: the job's outcome is its row, and a retry of a deterministic
 * failure would only write the same row again.
 */
final readonly class RunExport
{
    /** A week is long enough to find the file on Monday and short enough that nothing is kept by accident. */
    public const string RETENTION = '+7 days';

    public function __construct(
        private ExportJobStore $jobs,
        private ExportRenderer $renderer,
        private ExportFileStorage $files,
        private NotificationStore $notifications,
        private Clock $clock,
    ) {}

    public function __invoke(string $id): void
    {
        $job = $this->jobs->findForWorker($id);

        if ($job === null || $job->status->isFinished()) {
            return;
        }

        $this->jobs->markRunning($id, $this->clock->now());

        try {
            $file = $this->renderer->render($job->kind, $job->subjectId);
            $path = $this->files->put($job->workspaceId, $id, $job->kind->extension(), $file->bytes);
            $now = $this->clock->now();

            $this->jobs->markReady($id, $path, $file->filename, strlen($file->bytes), $now, $now->modify(self::RETENTION));

            $this->notifications->announce(
                $job->workspaceId,
                [$job->userId],
                NotificationKind::ExportReady,
                sprintf('Your export is ready: %s', $file->filename),
                null,
                NotificationKind::ExportReady->subject(),
                $id,
                null,
            );
        } catch (Throwable $failure) {
            report($failure);
            $this->jobs->markFailed($id, 'The file could not be produced. Try again, or ask for it from the report directly.', $this->clock->now());
        }
    }

    /** Called by the worker when the job itself dies before `__invoke` can record anything. */
    public function fail(string $id): void
    {
        $job = $this->jobs->findForWorker($id);

        if ($job !== null && ! $job->status->isFinished()) {
            $this->jobs->markFailed($id, 'The export did not finish. Try again.', $this->clock->now());
        }
    }

    public function status(string $id): ?ExportStatus
    {
        return $this->jobs->findForWorker($id)?->status;
    }
}
