<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Domain\Operations\ClientRequestKind;

/**
 * How a caller narrows the intake queue.
 *
 * One object for the same reason the other two filters are: the lists and the figures beside them must be
 * narrowed identically, or a count describes a wider population than the rows under it.
 */
final readonly class ClientRequestFilter
{
    public function __construct(
        public ?string $clientId = null,
        public ?ClientRequestKind $kind = null,
        /** True to show only what the agency is holding up, excluding what is waiting on a client. */
        public bool $onTheAgencyOnly = false,
    ) {}

    public static function everything(): self
    {
        return new self;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'kind' => $this->kind?->value,
            'on_the_agency' => $this->onTheAgencyOnly,
        ];
    }
}
