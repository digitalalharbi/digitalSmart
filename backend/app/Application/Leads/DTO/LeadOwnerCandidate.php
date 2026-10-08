<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

/** Somebody a lead can be put in the name of: an id and a name, and deliberately nothing else. */
final readonly class LeadOwnerCandidate
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
