<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Tags\Contract\TagStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentTagStore implements TagStore
{
    public function __construct(private IdentifierGenerator $ids) {}

    /** @return list<array{id: string, name: string, slug: string, usage: int}> */
    public function all(string $workspaceId): array
    {
        $out = [];

        foreach (DB::table('tags as t')->where('t.workspace_id', $workspaceId)
            ->selectRaw('t.id, t.name, t.slug, (select count(*) from taggings g where g.tag_id = t.id) as usage')
            ->orderBy('t.name')->get() as $row) {
            if ($row instanceof stdClass) {
                $out[] = ['id' => (string) $row->id, 'name' => (string) $row->name, 'slug' => (string) $row->slug, 'usage' => (int) $row->usage];
            }
        }

        return $out;
    }

    /** @return array{id: string, name: string, slug: string}|null */
    public function create(string $workspaceId, string $name): ?array
    {
        $slug = Str::slug($name) ?: 'tag';
        $id = $this->ids->generate();

        $inserted = DB::table('tags')->insertOrIgnore([
            'id' => $id, 'workspace_id' => $workspaceId, 'name' => trim($name), 'slug' => $slug,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $inserted === 0 ? null : ['id' => $id, 'name' => trim($name), 'slug' => $slug];
    }

    public function exists(string $workspaceId, string $tagId): bool
    {
        return DB::table('tags')->where(['workspace_id' => $workspaceId, 'id' => $tagId])->exists();
    }

    public function delete(string $workspaceId, string $tagId): void
    {
        DB::table('tags')->where(['workspace_id' => $workspaceId, 'id' => $tagId])->delete();
    }

    public function apply(string $workspaceId, string $tagId, string $type, string $id): void
    {
        DB::table('taggings')->insertOrIgnore([
            'id' => $this->ids->generate(), 'workspace_id' => $workspaceId, 'tag_id' => $tagId,
            'entity_type' => $type, 'entity_id' => $id, 'created_at' => now(),
        ]);
    }

    public function remove(string $workspaceId, string $tagId, string $type, string $id): void
    {
        DB::table('taggings')->where(['workspace_id' => $workspaceId, 'tag_id' => $tagId, 'entity_type' => $type, 'entity_id' => $id])->delete();
    }

    /**
     * @param  list<array{type: string, id: string}>  $entities
     * @return array<string, list<array{id: string, name: string}>>
     */
    public function forEntities(string $workspaceId, array $entities): array
    {
        if ($entities === []) {
            return [];
        }

        $out = [];
        $ids = array_column($entities, 'id');

        foreach (DB::table('taggings as g')->join('tags as t', 't.id', '=', 'g.tag_id')
            ->where('g.workspace_id', $workspaceId)->whereIn('g.entity_id', $ids)
            ->orderBy('t.name')->get(['g.entity_type', 'g.entity_id', 't.id', 't.name']) as $row) {
            if ($row instanceof stdClass) {
                $out[$row->entity_type.':'.$row->entity_id][] = ['id' => (string) $row->id, 'name' => (string) $row->name];
            }
        }

        return $out;
    }
}
