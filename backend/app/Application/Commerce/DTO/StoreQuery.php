<?php

declare(strict_types=1);

namespace App\Application\Commerce\DTO;

use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * What a reader is asking of a client's stores.
 *
 * `revenueSource` is required and has no default, which is the point. There is no "all sources" option
 * and there never will be: a Salla total and a GA4 total measure different things, and an interface that
 * let somebody ask for both at once would be offering a number nobody could defend.
 */
final readonly class StoreQuery
{
    /** @param list<string>|null $visibleProjectIds null means every project in the workspace */
    public function __construct(
        public string $workspaceId,
        public RevenueSource $revenueSource,
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        public ?array $visibleProjectIds = null,
        public ?string $clientId = null,
        public ?string $storeId = null,
        /** Rows to return from a breakdown. The rest are not silently dropped — see the use case. */
        public int $limit = 20,
    ) {}
}
