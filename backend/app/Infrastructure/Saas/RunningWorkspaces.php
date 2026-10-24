<?php

declare(strict_types=1);

namespace App\Infrastructure\Saas;

use App\Domain\Saas\SubscriptionStatus;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Narrow a scheduler's query to workspaces whose subscription lets scheduled work run. One SQL expression, applied by every
 * place that enumerates workspaces for a sync, a rule run, an agent or a digest, so a suspended workspace is skipped by all of
 * them or by none.
 */
final class RunningWorkspaces
{
    public static function only(Builder $query, string $workspaceColumn): Builder
    {
        $stopped = array_values(array_map(
            static fn (SubscriptionStatus $s): string => $s->value,
            array_filter(SubscriptionStatus::cases(), static fn (SubscriptionStatus $s): bool => ! $s->runsScheduledWork()),
        ));

        return $query->whereNotExists(static fn ($q) => $q->select(DB::raw(1))->from('workspace_subscriptions as rw_s')
            ->whereColumn('rw_s.workspace_id', $workspaceColumn)
            ->whereIn('rw_s.status', $stopped));
    }
}
