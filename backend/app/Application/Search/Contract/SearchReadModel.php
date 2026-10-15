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
}
