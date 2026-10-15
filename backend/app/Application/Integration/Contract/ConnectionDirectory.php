<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Application\Integration\DTO\AccountRow;
use App\Application\Integration\DTO\ConnectionView;
use DateTimeImmutable;

/**
 * The connection-level read model: every connection with its account counts, and every account with
 * what it is bound to, in a fixed number of statements however large the agency is.
 */
interface ConnectionDirectory
{
    /** @return list<ConnectionView> */
    public function connections(string $workspaceId, bool $withTechnicalDetail): array;

    /**
     * @param  list<string>|null  $visibleProjectIds  Null is unrestricted. A scoped reader sees only accounts
     *                                                bound to their projects — never the unassigned queue.
     * @param  list<string>  $states  unassigned | bound | shared | ignored; empty means all
     * @return list<AccountRow>
     */
    public function accounts(
        string $workspaceId,
        ?array $visibleProjectIds,
        array $states,
        ?string $connectionId,
        int $limit,
        int $offset,
    ): array;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return array<string, int> count per state
     */
    public function accountCounts(string $workspaceId, ?array $visibleProjectIds): array;

    public function ignore(string $workspaceId, string $accountId, string $userId, DateTimeImmutable $at): bool;

    public function restore(string $workspaceId, string $accountId): bool;
}
