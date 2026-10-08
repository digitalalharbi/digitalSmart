<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

/**
 * Where an uploaded file's bytes live.
 *
 * A port, so the use case never names a disk. Locally that is the private local disk; in production it is a
 * bucket, and the bucket is **BLOCKED_EXTERNAL** — no cloud account is attached to this repository. The contract
 * is the same either way, which is the point: nothing above this line changes when one arrives.
 *
 * Two things it deliberately does **not** offer:
 *
 * **No public URL.** Every read goes through the API, because the audience rule lives there. A signed bucket URL
 * would be a second place that rule has to hold, and the first time the two disagreed a client would be reading
 * an internal file.
 *
 * **No path from the caller.** The store decides where bytes go. A path built from anything an uploader supplied
 * is a path traversal waiting for somebody to try `../`.
 */
interface AttachmentStore
{
    /**
     * Store these bytes and return the name they were stored under.
     *
     * @param  string  $extension  from the sniffed type, never from the uploaded filename
     */
    public function put(string $workspaceId, string $contents, string $extension): string;

    /** The bytes back, or null when the record points at a file that is no longer there. */
    public function read(string $storedName): ?string;

    /** Remove one file. Called when its record is deleted, and never on its own. */
    public function delete(string $storedName): void;
}
