<?php

declare(strict_types=1);

namespace App\Application\Client\DTO;

use App\Domain\Client\ClientStatus;

final readonly class ClientFilters
{
    /** @param list<ClientStatus> $statuses */
    public function __construct(
        public ?string $search = null,
        public array $statuses = [],
        public ?bool $isDemo = null,
        public string $sort = 'name',
        public string $direction = 'asc',
    ) {}

    /** @return list<string> */
    public function statusValues(): array
    {
        return array_map(static fn (ClientStatus $status): string => $status->value, $this->statuses);
    }
}
