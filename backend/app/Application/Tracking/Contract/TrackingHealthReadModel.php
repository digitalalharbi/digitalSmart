<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use DateTimeImmutable;

interface TrackingHealthReadModel
{
    /**
     * The facts the health checks rest on, over the last seven days.
     *
     * @return array<string, mixed>
     */
    public function facts(string $workspaceId, string $projectId, ?string $linkId, DateTimeImmutable $now): array;

    /**
     * Recent events of every class, newest last, for the debug console.
     *
     * @return list<array<string, mixed>>
     */
    public function recentEvents(string $workspaceId, string $projectId, ?string $touchpointId, ?DateTimeImmutable $after, int $limit): array;
}
