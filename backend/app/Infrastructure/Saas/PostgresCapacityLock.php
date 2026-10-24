<?php

declare(strict_types=1);

namespace App\Infrastructure\Saas;

use App\Application\Saas\Contract\CapacityLock;
use App\Domain\Saas\Limit;
use Illuminate\Support\Facades\DB;

/**
 * A transaction-scoped advisory lock per workspace and limit: the second of two concurrent claims waits for the first to commit,
 * then counts what it created. Released at commit or rollback, so it can never be left held.
 */
final readonly class PostgresCapacityLock implements CapacityLock
{
    public function within(string $workspaceId, Limit $limit, callable $work): mixed
    {
        return DB::transaction(static function () use ($workspaceId, $limit, $work): mixed {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['capacity:'.$workspaceId.':'.$limit->value]);

            return $work();
        });
    }
}
