<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\IntakeLoad;
use DateTimeImmutable;

/** The intake queue: columns, the figures, and which side of the conversation is reading. */
final readonly class IntakeQueueView
{
    /** @param array<string, list<ClientRequestView>> $columns keyed by state value */
    public function __construct(
        public array $columns,
        public IntakeLoad $load,
        public DateTimeImmutable $now,
        /** False for a client reading their own requests, in which case internal notes were never selected. */
        public bool $isAgencyView,
        public bool $canTriage,
    ) {}

    /** @return list<ClientRequestView> */
    public function column(ClientRequestState $state): array
    {
        return $this->columns[$state->value] ?? [];
    }

    public function inState(ClientRequestState $state): int
    {
        return $this->load->byState[$state->value] ?? 0;
    }
}
