<?php

declare(strict_types=1);

namespace App\Application\Search\DTO;

/** One thing found, named the way the product names it elsewhere, with where to open it. */
final readonly class SearchHit
{
    public function __construct(
        /** client | project | campaign | account | lead | report */
        public string $type,
        public string $id,
        public string $label,
        /** The parent a human uses to tell two of the same name apart: a project's client, a campaign's account. */
        public ?string $context,
        public string $path,
    ) {}
}
