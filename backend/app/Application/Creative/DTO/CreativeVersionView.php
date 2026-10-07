<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\AssetMeasurements;
use App\Domain\Creative\SpecReport;
use DateTimeImmutable;

/** One version, what was measured about it, what the spec made of that, and who decided what. */
final readonly class CreativeVersionView
{
    public function __construct(
        public string $id,
        public int $number,
        public AssetKind $kind,
        public AssetMeasurements $measurements,
        public DateTimeImmutable $createdAt,
        public ?string $label = null,
        public ?string $storagePath = null,
        public ?string $body = null,
        public ?string $uploadedByName = null,
        /**
         * Null until a query composes one.
         *
         * Composed in the application layer rather than stored, because the specification it is checked
         * against changes when somebody records a newer reading of the platform's page — and a stored
         * verdict would then be a confident claim citing a page nobody has read since.
         */
        public ?SpecReport $spec = null,
        public ?ApprovalOutcome $decision = null,
        public ?string $decisionReason = null,
        public ?string $decidedByName = null,
        public ?DateTimeImmutable $decidedAt = null,
    ) {}

    /** Whether this version is the one a client signed off. Never inherited by a later one. */
    public function isApproved(): bool
    {
        return $this->decision === ApprovalOutcome::Approved;
    }
}
