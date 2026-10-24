<?php

declare(strict_types=1);

namespace App\Application\Saas\Contract;

use App\Domain\Saas\Limit;

/**
 * Serialises the check-then-create of one limit in one workspace, so two people adding the last seat at once cannot both get it.
 */
interface CapacityLock
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function within(string $workspaceId, Limit $limit, callable $work): mixed;
}
