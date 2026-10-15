<?php

declare(strict_types=1);

namespace App\Application\Export\Contract;

interface ExportFileStorage
{
    /** @return string the path to hand back to `read` and `delete` */
    public function put(string $workspaceId, string $id, string $extension, string $bytes): string;

    public function read(string $path): ?string;

    public function delete(string $path): void;
}
