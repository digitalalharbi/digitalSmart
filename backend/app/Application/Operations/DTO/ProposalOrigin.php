<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use DateTimeImmutable;

/** Who proposed a change and on what, when it was not a person typing a request. */
final readonly class ProposalOrigin
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public string $agent,
        public array $evidence,
        public string $risk,
        public DateTimeImmutable $expiresAt,
    ) {}
}
