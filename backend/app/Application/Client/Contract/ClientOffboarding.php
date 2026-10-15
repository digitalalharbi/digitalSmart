<?php

declare(strict_types=1);

namespace App\Application\Client\Contract;

use DateTimeImmutable;

/**
 * What leaving costs, and the act of leaving — without deleting anything.
 *
 * Both methods return the same shape so the preview a person confirms is, by construction, a description of
 * what the act then does.
 */
interface ClientOffboarding
{
    /**
     * @return array{
     *     projects_closing: int,
     *     bindings_paused: int,
     *     connections_disconnected: int,
     *     portal_suspended: bool,
     *     share_links_revoked: int,
     *     open_tasks: int,
     *     open_requests: int
     * }
     */
    public function preview(string $workspaceId, string $clientId): array;

    /**
     * @return array{
     *     projects_closing: int,
     *     bindings_paused: int,
     *     connections_disconnected: int,
     *     portal_suspended: bool,
     *     share_links_revoked: int,
     *     open_tasks: int,
     *     open_requests: int
     * }
     */
    public function apply(string $workspaceId, string $clientId, string $userId, string $reason, DateTimeImmutable $at): array;
}
