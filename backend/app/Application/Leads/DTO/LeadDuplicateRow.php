<?php

declare(strict_types=1);

namespace App\Application\Leads\DTO;

use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use DateTimeImmutable;

/**
 * Another lead that may be the same person, with what makes it look that way.
 *
 * **`matchedOn` is the whole point.** A suggestion with no evidence beside it is a suggestion somebody accepts
 * without thinking, and merging two people's histories is not a mistake this product can undo. So the reader is
 * told which contact point matched, exactly — never "looks similar", because nothing here matches on similarity.
 */
final readonly class LeadDuplicateRow
{
    public function __construct(
        public string $id,
        public LeadSourceKind $sourceKind,
        public ?string $sourceDetail,
        public LeadStage $stage,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public DateTimeImmutable $receivedAt,
        /** `email`, `phone`, or both where both matched. */
        public string $matchedOn,
        public string $matchedOnLabel,
    ) {}
}
