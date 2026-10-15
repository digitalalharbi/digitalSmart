<?php

declare(strict_types=1);

namespace App\Application\Export\Contract;

use App\Application\Export\DTO\RenderedFile;
use App\Domain\Export\ExportKind;

/**
 * Produces the file. Runs as the requester — the actor is bound before this is called — so whatever the
 * rendering use case would refuse them live, it refuses them here too.
 */
interface ExportRenderer
{
    public function render(ExportKind $kind, string $subjectId): RenderedFile;
}
