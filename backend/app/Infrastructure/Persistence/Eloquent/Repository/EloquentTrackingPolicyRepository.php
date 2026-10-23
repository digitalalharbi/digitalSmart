<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingPolicyRepository;
use App\Domain\Tracking\Dispatch\ConsentPolicy;
use Illuminate\Support\Facades\DB;

final readonly class EloquentTrackingPolicyRepository implements TrackingPolicyRepository
{
    public function for(string $projectId): array
    {
        $row = DB::table('tracking_settings')->where('project_id', $projectId)->first(['consent_policy', 'retention_days']);

        return [
            'consent_policy' => $row === null ? ConsentPolicy::Required : (ConsentPolicy::tryFrom((string) $row->consent_policy) ?? ConsentPolicy::Required),
            'retention_days' => $row === null ? 400 : (int) $row->retention_days,
        ];
    }

    public function save(string $workspaceId, string $projectId, ConsentPolicy $consent, int $retentionDays, string $actorId): void
    {
        $now = now();

        if (DB::table('tracking_settings')->where('project_id', $projectId)->exists()) {
            DB::table('tracking_settings')->where('project_id', $projectId)
                ->update(['consent_policy' => $consent->value, 'retention_days' => $retentionDays, 'updated_by' => $actorId, 'updated_at' => $now]);

            return;
        }

        DB::table('tracking_settings')->insert([
            'project_id' => $projectId, 'workspace_id' => $workspaceId, 'datalayer' => '{}', 'consent_policy' => $consent->value,
            'retention_days' => $retentionDays, 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
