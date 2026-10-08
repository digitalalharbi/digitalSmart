<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\Contract\AttachmentStore;
use App\Application\Shared\Contract\IdentifierGenerator;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Attachments on a configured disk, under names this product generated.
 *
 * The disk is `digitalsmart.attachment_disk`, which is the private local disk in development and is intended to
 * be a bucket in production — **BLOCKED_EXTERNAL**, since no cloud account is attached to this repository. The
 * driver is the only thing that changes; nothing above this class knows which it is.
 *
 * Three decisions, and each is a defence rather than a convenience.
 *
 * **The name is a generated identifier plus the extension of the *sniffed* type.** Never the uploaded filename:
 * a path built from one is a path traversal, and an extension taken from one is how a browser is talked into
 * executing something. Nothing of the uploader's survives into the filesystem.
 *
 * **Files are partitioned by workspace**, so one tenant's uploads are not interleaved with another's on disk.
 * That is not the access control — the API is — but it makes a mistake with a wildcard recoverable instead of
 * catastrophic.
 *
 * **Nothing is public.** The local disk's root is `storage/app/private`, there is no URL, and the only way to
 * read a file is the endpoint that applies the audience rule.
 */
final readonly class LocalAttachmentStore implements AttachmentStore
{
    public function __construct(
        private FilesystemFactory $filesystem,
        private IdentifierGenerator $ids,
        private string $disk,
    ) {}

    public function put(string $workspaceId, string $contents, string $extension): string
    {
        $name = sprintf('client-requests/%s/%s.%s', $workspaceId, $this->ids->generate(), $extension);

        $this->filesystem->disk($this->disk)->put($name, $contents);

        return $name;
    }

    public function read(string $storedName): ?string
    {
        $disk = $this->filesystem->disk($this->disk);

        /*
         * `exists` before `get`, because the configured disks neither throw nor report: a missing file would
         * otherwise come back as `false` and be indistinguishable from an empty one, and "this file is not there"
         * is a different answer from "this file is empty".
         */
        if (! $disk->exists($storedName)) {
            return null;
        }

        $contents = $disk->get($storedName);

        return is_string($contents) ? $contents : null;
    }

    public function delete(string $storedName): void
    {
        $this->filesystem->disk($this->disk)->delete($storedName);
    }
}
