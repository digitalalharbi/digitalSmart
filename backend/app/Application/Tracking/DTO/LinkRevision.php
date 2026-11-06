<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

use DateTimeImmutable;

/** One state of a tracking link: what a click carried between `effectiveFrom` and `effectiveUntil`. */
final readonly class LinkRevision
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public int $revision,
        public array $snapshot,
        public array $changedFields,
        public ?string $changedByName,
        /** True when the values were copied from the link as it stood when history began, not recorded at the time. */
        public bool $backfilled,
        public DateTimeImmutable $effectiveFrom,
        /** Null for the revision in force now. */
        public ?DateTimeImmutable $effectiveUntil,
        /** Valid clicks that arrived while this revision was in force. */
        public int $clicks,
    ) {}
}
