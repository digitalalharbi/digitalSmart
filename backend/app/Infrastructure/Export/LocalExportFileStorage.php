<?php

declare(strict_types=1);

namespace App\Infrastructure\Export;

use App\Application\Export\Contract\ExportFileStorage;
use Illuminate\Support\Facades\Storage;

/** The private disk — exports are a client's numbers, and nothing here is ever publicly addressable. */
final readonly class LocalExportFileStorage implements ExportFileStorage
{
    public function put(string $workspaceId, string $id, string $extension, string $bytes): string
    {
        /* Under the tenant's own prefix. The stored path is read back as stored, so older files stay readable. */
        $path = sprintf('workspaces/%s/exports/%s.%s', $workspaceId, $id, $extension);
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    public function read(string $path): ?string
    {
        $disk = Storage::disk('local');

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function delete(string $path): void
    {
        Storage::disk('local')->delete($path);
    }
}
