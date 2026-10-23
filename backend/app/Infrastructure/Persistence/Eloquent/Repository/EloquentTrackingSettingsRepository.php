<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingSettingsRepository;
use Illuminate\Support\Facades\DB;

final readonly class EloquentTrackingSettingsRepository implements TrackingSettingsRepository
{
    /** @return array<string, string> */
    public function dataLayerOverrides(string $projectId): array
    {
        $json = DB::table('tracking_settings')->where('project_id', $projectId)->value('datalayer');
        $decoded = is_string($json) ? json_decode($json, true) : [];
        $out = [];

        foreach (is_array($decoded) ? $decoded : [] as $event => $name) {
            if (is_string($name)) {
                $out[(string) $event] = $name;
            }
        }

        return $out;
    }

    public function saveDataLayer(string $workspaceId, string $projectId, array $overrides, string $actorId): void
    {
        $now = now();

        DB::table('tracking_settings')->upsert(
            [[
                'project_id' => $projectId, 'workspace_id' => $workspaceId, 'updated_by' => $actorId,
                'datalayer' => json_encode($overrides, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), 'created_at' => $now, 'updated_at' => $now,
            ]],
            ['project_id'],
            ['datalayer', 'updated_by', 'updated_at'],
        );
    }
}
