<?php

declare(strict_types=1);

namespace App\Application\Shared\DTO;

/** Somebody a record can be put in the name of: an id and a name, and deliberately nothing else. */
final readonly class OwnerCandidate
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
