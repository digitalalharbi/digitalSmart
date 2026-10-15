<?php

declare(strict_types=1);

namespace App\Application\Portfolio\Contract;

use DateTimeImmutable;

/**
 * Outcomes that are not campaign performance: people who enquired, orders that were paid, and how many
 * clients and projects are in flight. Each is one grouped statement over the whole agency.
 */
interface PortfolioReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, array{leads: int, qualified: int}> per client id
     */
    public function leads(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Paid orders per client, with their value per currency. Never summed across currencies.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, array{orders: int, revenue: array<string, int>}> per client id
     */
    public function sales(string $workspaceId, ?array $visibleProjectIds, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Client and project identity and lifecycle, restricted to what the reader may see.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array{clients: list<array{id: string, name: string, status: string}>, projects: list<array{id: string, client_id: string, name: string, status: string}>}
     */
    public function roster(string $workspaceId, ?array $visibleProjectIds, ?string $clientId): array;

    /**
     * Open work per project: tasks not done or cancelled, and requests the agency still holds.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, int> per project id
     */
    public function openWork(string $workspaceId, ?array $visibleProjectIds): array;
}
