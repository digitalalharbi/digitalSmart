<?php

declare(strict_types=1);

namespace App\Application\Tags\Contract;

interface TagStore
{
    /** @return list<array{id: string, name: string, slug: string, usage: int}> */
    public function all(string $workspaceId): array;

    /** @return array{id: string, name: string, slug: string}|null null when the name already exists */
    public function create(string $workspaceId, string $name): ?array;

    public function exists(string $workspaceId, string $tagId): bool;

    public function delete(string $workspaceId, string $tagId): void;

    public function apply(string $workspaceId, string $tagId, string $type, string $id): void;

    public function remove(string $workspaceId, string $tagId, string $type, string $id): void;

    /**
     * Tags on a set of entities, in one statement.
     *
     * @param  list<array{type: string, id: string}>  $entities
     * @return array<string, list<array{id: string, name: string}>> keyed "type:id"
     */
    public function forEntities(string $workspaceId, array $entities): array;
}
