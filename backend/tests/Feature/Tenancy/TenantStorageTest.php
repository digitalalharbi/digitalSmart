<?php

declare(strict_types=1);

use App\Application\Export\Contract\ExportFileStorage;
use App\Application\Operations\Contract\AttachmentStore;
use Illuminate\Support\Facades\Storage;

/*
| Every file this product writes lives under its tenant's own prefix, on a private disk, and is read back only through an
| endpoint that re-applies the reader's access. A wildcard mistake in an operator's script then touches one tenant, not all.
*/

it('stores attachments and exports under workspaces/{workspace}/', function (): void {
    Storage::fake('local');
    Storage::fake(config('filesystems.default'));
    $workspace = '01a16c94-0000-7000-8000-000000000001';

    $attachment = app(AttachmentStore::class)->put($workspace, 'bytes', 'png');
    $export = app(ExportFileStorage::class)->put($workspace, 'export-1', 'csv', 'a,b');

    expect($attachment)->toStartWith("workspaces/{$workspace}/client-requests/")
        ->and($export)->toStartWith("workspaces/{$workspace}/exports/")
        ->and(app(AttachmentStore::class)->read($attachment))->toBe('bytes')
        ->and(app(ExportFileStorage::class)->read($export))->toBe('a,b');
});
