<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\ConversionMappingRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentConversionMappingRepository implements ConversionMappingRepository
{
    public function list(string $workspaceId, string $projectId): array
    {
        $rows = DB::table('conversion_mappings')->where('workspace_id', $workspaceId)->where('project_id', $projectId)
            ->orderBy('event')->orderBy('provider')->get();

        return array_values($rows->map(fn (stdClass $r): array => $this->row($r))->all());
    }

    public function save(string $workspaceId, string $projectId, string $event, string $provider, string $providerEvent, bool $enabled, string $actorId): array
    {
        $now = now();
        $existing = DB::table('conversion_mappings')->where('project_id', $projectId)->where('event', $event)->where('provider', $provider)->value('id');

        if (is_string($existing)) {
            DB::table('conversion_mappings')->where('id', $existing)->update(['provider_event' => $providerEvent, 'enabled' => $enabled, 'updated_at' => $now]);
            $id = $existing;
        } else {
            $id = (string) Str::uuid7();
            DB::table('conversion_mappings')->insert([
                'id' => $id, 'workspace_id' => $workspaceId, 'project_id' => $projectId, 'event' => $event, 'provider' => $provider,
                'provider_event' => $providerEvent, 'enabled' => $enabled, 'created_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return ['id' => $id, 'event' => $event, 'provider' => $provider, 'provider_event' => $providerEvent, 'enabled' => $enabled];
    }

    public function delete(string $workspaceId, string $projectId, string $id): bool
    {
        return DB::table('conversion_mappings')->where('workspace_id', $workspaceId)->where('project_id', $projectId)->where('id', $id)->delete() > 0;
    }

    public function enabledFor(string $projectId, string $event): array
    {
        $rows = DB::table('conversion_mappings')->where('project_id', $projectId)->where('event', $event)->where('enabled', true)->get();

        return array_values($rows->map(fn (stdClass $r): array => $this->row($r))->all());
    }

    /** @return array{id: string, event: string, provider: string, provider_event: string, enabled: bool} */
    private function row(stdClass $r): array
    {
        return ['id' => (string) $r->id, 'event' => (string) $r->event, 'provider' => (string) $r->provider, 'provider_event' => (string) $r->provider_event, 'enabled' => (bool) $r->enabled];
    }
}
