<?php

declare(strict_types=1);

namespace App\Application\Access\DTO;

use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use DateTimeImmutable;

/** One request for an account, as an administrator reads it. */
final readonly class AccessRequestView
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $company,
        public ?string $phone,
        public AccessRequestNeed $need,
        public ?string $message,
        public string $locale,
        public AccessRequestStatus $status,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $reviewedAt,
        public ?string $reviewedByName,
        public ?string $reviewNote,
    ) {}
}
