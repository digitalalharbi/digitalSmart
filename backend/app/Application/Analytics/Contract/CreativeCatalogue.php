<?php

declare(strict_types=1);

namespace App\Application\Analytics\Contract;

use App\Application\Analytics\DTO\CreativeInfo;

/**
 * What the creatives and ads in a read are, apart from how they performed.
 *
 * Two lookups by key, each one query however many keys — never one per row, and never a call to a platform: every address
 * here was stored when the creative was synced, so showing a creative cannot wait on, or fail because of, somebody else's
 * server. A key this workspace does not own is simply not in the answer.
 */
interface CreativeCatalogue
{
    /**
     * @param  list<string>  $creativeIds
     * @return array<string, CreativeInfo> keyed by creative
     */
    public function assets(string $workspaceId, array $creativeIds): array;

    /**
     * @param  list<string>  $adIds
     * @return array<string, CreativeInfo> keyed by ad
     */
    public function ads(string $workspaceId, array $adIds): array;
}
