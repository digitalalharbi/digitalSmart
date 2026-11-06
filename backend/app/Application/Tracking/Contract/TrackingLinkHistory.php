<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\LinkRevision;

/** What a tracking link said at each point in its life. Append-only. */
interface TrackingLinkHistory
{
    /**
     * Write a revision if the snapshot differs from the latest one; a save that changed nothing a click carries writes
     * nothing. Returns the revision number written, or null.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function record(string $workspaceId, string $linkId, array $snapshot, ?string $actorId): ?int;

    /**
     * Newest first, each with the valid clicks that arrived while it was in force.
     *
     * @return list<LinkRevision>
     */
    public function revisions(string $workspaceId, string $linkId): array;
}
