<?php

declare(strict_types=1);

namespace App\Application\Access\DTO;

use App\Domain\Access\AccessRequestKind;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\PreferredContact;
use DateTimeImmutable;

final readonly class NewAccessRequest
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public AccessRequestKind $kind,
        public string $name,
        public string $email,
        public ?string $company,
        public ?string $phone,
        public ?string $phoneKey,
        public ?string $jobTitle,
        public PreferredContact $preferredContact,
        public ?AccessRequestNeed $need,
        public ?string $message,
        public string $locale,
        public ?string $ownerId,
        public ?string $possibleDuplicateOf,
        public DateTimeImmutable $at,
    ) {}
}
