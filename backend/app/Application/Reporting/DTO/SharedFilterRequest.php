<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use DateTimeImmutable;

/**
 * What a visitor asked to narrow a shared report to.
 *
 * Raw and untrusted: opaque keys the page was handed, and dates. Nothing here is an authority — every value is
 * checked against the link's own scope before it changes a query, and a value outside it is refused.
 */
final readonly class SharedFilterRequest
{
    /**
     * @param  list<string>  $platforms  platform slugs
     * @param  list<string>  $campaigns  opaque keys from the report's own option list
     */
    public function __construct(
        public array $platforms = [],
        public array $campaigns = [],
        public ?string $store = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $until = null,
        /** The visitor already opened this page in this session, so this read is a refinement and not a view. */
        public bool $refinement = false,
        /** @var list<string> opaque keys the link handed out for its creatives */
        public array $creatives = [],
        /** A creative format the report's own creatives hold, by its slug. */
        public ?string $format = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->platforms === []
            && $this->campaigns === []
            && $this->store === null
            && $this->from === null
            && $this->until === null
            && $this->creatives === []
            && $this->format === null;
    }
}
