<?php

declare(strict_types=1);

namespace App\Application\Export\Command;

use App\Application\Export\Contract\ExportFileStorage;
use App\Application\Export\Contract\ExportJobStore;
use App\Application\Shared\Contract\Clock;

/** Deletes files past their retention. The row stays, so "what did I export last month" still has an answer. */
final readonly class PruneExports
{
    public function __construct(
        private ExportJobStore $jobs,
        private ExportFileStorage $files,
        private Clock $clock,
    ) {}

    public function __invoke(): int
    {
        $removed = 0;

        foreach ($this->jobs->lapsed($this->clock->now(), 500) as $lapsed) {
            $this->files->delete($lapsed['path']);
            $this->jobs->clearFile($lapsed['id']);
            $removed++;
        }

        return $removed;
    }
}
