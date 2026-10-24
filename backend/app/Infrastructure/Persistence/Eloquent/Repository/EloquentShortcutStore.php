<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shortcuts\Contract\ShortcutStore;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentShortcutStore implements ShortcutStore
{
    public function __construct(private IdentifierGenerator $ids) {}

    public function belongs(string $workspaceId, string $type, string $id): bool
    {
        $table = match ($type) {
            'client' => 'clients',
            'project' => 'projects',
            'campaign' => 'campaigns',
            default => null,
        };

        return $table !== null && DB::table($table)->where('id', $id)->where('workspace_id', $workspaceId)->exists();
    }

    public function pin(string $workspaceId, string $userId, string $type, string $id, DateTimeImmutable $at): void
    {
        DB::table('user_pins')->insertOrIgnore([
            'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'user_id' => $userId,
            'entity_type' => $type, 'entity_id' => $id, 'created_at' => $at,
        ]);
    }

    public function unpin(string $workspaceId, string $userId, string $type, string $id): void
    {
        DB::table('user_pins')->where(['workspace_id' => $workspaceId, 'user_id' => $userId, 'entity_type' => $type, 'entity_id' => $id])->delete();
    }

    public function touch(string $workspaceId, string $userId, string $type, string $id, DateTimeImmutable $at, int $keep): void
    {
        DB::table('user_recents')->upsert(
            [['id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'user_id' => $userId,
                'entity_type' => $type, 'entity_id' => $id, 'viewed_at' => $at]],
            ['user_id', 'workspace_id', 'entity_type', 'entity_id'],
            ['viewed_at'],
        );

        /* Pruned on write, so the table is bounded by people × $keep and never by the size of the agency. */
        $stale = DB::table('user_recents')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])
            ->orderByDesc('viewed_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');

        if ($stale->isNotEmpty()) {
            DB::table('user_recents')->whereIn('id', $stale->all())->delete();
        }
    }

    /** @return list<array{type: string, id: string}> */
    public function pinned(string $workspaceId, string $userId): array
    {
        return $this->map(DB::table('user_pins')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])
            ->orderByDesc('created_at')->limit(100)->get(['entity_type', 'entity_id']));
    }

    /** @return list<array{type: string, id: string}> */
    public function recent(string $workspaceId, string $userId, int $limit): array
    {
        return $this->map(DB::table('user_recents')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])
            ->orderByDesc('viewed_at')->limit($limit)->get(['entity_type', 'entity_id']));
    }

    /**
     * @param  iterable<mixed, mixed>  $rows
     * @return list<array{type: string, id: string}>
     */
    private function map(iterable $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            if ($r instanceof stdClass) {
                $out[] = ['type' => (string) $r->entity_type, 'id' => (string) $r->entity_id];
            }
        }

        return $out;
    }
}
