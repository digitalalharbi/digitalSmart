<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Attention\Contract\ActorMemberships;
use Illuminate\Support\Facades\DB;

final readonly class EloquentActorMemberships implements ActorMemberships
{
    public function projectIds(string $workspaceId, string $userId): array
    {
        return array_values(array_map('strval', DB::table('project_members as m')
            ->join('projects as p', 'p.id', '=', 'm.project_id')
            ->where('p.workspace_id', $workspaceId)->where('m.user_id', $userId)
            ->pluck('m.project_id')->all()));
    }

    public function clientIds(string $workspaceId, string $userId): array
    {
        return array_values(array_map('strval', DB::table('project_members as m')
            ->join('projects as p', 'p.id', '=', 'm.project_id')
            ->where('p.workspace_id', $workspaceId)->where('m.user_id', $userId)
            ->distinct()->pluck('p.client_id')->all()));
    }
}
