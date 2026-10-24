<?php

declare(strict_types=1);

namespace App\Application\Saas\Contract;

use App\Domain\Saas\Limit;
use DateTimeImmutable;

/**
 * How much of a limit a workspace has used. Derived from the rows that exist wherever a table already records the thing, and
 * from idempotent usage records otherwise. `null` when the thing cannot be measured yet.
 */
interface UsageReader
{
    public function used(string $workspaceId, Limit $limit, DateTimeImmutable $now): ?int;

    /** Count one occurrence of a monthly meter, once per event key. True when it was newly counted. */
    public function record(string $workspaceId, Limit $limit, string $eventKey, DateTimeImmutable $at): bool;
}
