<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\AssetKind;
use DateTimeImmutable;

/** A brief as it arrives. */
final readonly class CreativeRequestInput
{
    public function __construct(
        public string $clientId,
        public string $title,
        public AssetKind $kind,
        public ?string $projectId = null,
        public ?string $brief = null,
        /**
         * Where the work is meant to run.
         *
         * Both optional, because a brief usually precedes the decision — and a request with no placement
         * is exactly why most specification checks honestly report having checked nothing.
         */
        public ?string $provider = null,
        public ?string $placement = null,
        public ?DateTimeImmutable $dueAt = null,
        public ?string $ownerId = null,
    ) {}
}
