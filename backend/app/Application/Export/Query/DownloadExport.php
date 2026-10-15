<?php

declare(strict_types=1);

namespace App\Application\Export\Query;

use App\Application\Export\Contract\ExportFileStorage;
use App\Application\Export\Contract\ExportJobStore;
use App\Application\Export\DTO\RenderedFile;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Export\ExportStatus;

/**
 * The bytes of a finished export — to the person who asked for it, and to nobody else.
 *
 * Not found for anyone else, rather than forbidden: a colleague learning that a file exists, and whose, is a
 * disclosure of its own.
 */
final readonly class DownloadExport
{
    public function __construct(
        private ExportJobStore $jobs,
        private ExportFileStorage $files,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    public function __invoke(string $id): RenderedFile
    {
        $this->guard->ensure(Permission::ReportsView);

        $job = $this->jobs->find($this->actor->workspaceId()->value, $this->actor->userId()->value, $id)
            ?? throw ResourceNotFound::of('Export');

        $path = $this->jobs->path($id);

        if ($job->status !== ExportStatus::Ready || $path === null || $job->isExpired($this->clock->now())) {
            throw ResourceNotFound::of('Export');
        }

        $bytes = $this->files->read($path) ?? throw ResourceNotFound::of('Export');

        return new RenderedFile((string) $job->filename, $job->kind->contentType(), $bytes);
    }
}
