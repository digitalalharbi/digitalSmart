<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

/** One discovered account with what it is bound to — the row of the binding workflow. */
final readonly class AccountRow
{
    /** @param list<array{id: string, name: string, client_name: string}> $projects */
    public function __construct(
        public string $id,
        public string $name,
        public string $provider,
        /** Secondary, by rule 92: the human label leads and the native id follows. */
        public string $externalId,
        public string $connectionId,
        public string $connectionLabel,
        public ?string $ownerClientId,
        public ?string $ownerClientName,
        public ?string $currency,
        public string $status,
        /** unassigned | bound | shared | ignored */
        public string $state,
        public array $projects,
        public int $campaigns,
    ) {}
}
