<?php

declare(strict_types=1);

namespace App\Application\Search\Contract;

use App\Application\Search\DTO\SearchHit;

interface SearchReadModel
{
    /**
     * @param  list<string>  $types
     * @param  list<string>|null  $visibleProjectIds  Null is every project; `[]` is none.
     * @return list<SearchHit>
     */
    public function search(string $workspaceId, ?array $visibleProjectIds, array $types, string $term, int $perType): array;

    /**
     * Resolve known entities to hits, narrowed to what the reader may see.
     *
     * What turns a stored pin or recent — a type and an id — into something a person can read, and drops
     * the ones that no longer resolve: deleted, or in a project the reader has since left.
     *
     * @param  list<array{type: string, id: string}>  $entities
     * @param  list<string>|null  $visibleProjectIds
     * @return list<SearchHit>
     */
    public function lookup(string $workspaceId, ?array $visibleProjectIds, array $entities): array;
}
