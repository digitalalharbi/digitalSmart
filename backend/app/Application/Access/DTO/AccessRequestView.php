<?php

declare(strict_types=1);

namespace App\Application\Access\DTO;

use App\Domain\Access\AccessRequestKind;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\PreferredContact;
use DateTimeImmutable;

/** One request as the people answering it see it. The account it may become is `accountUserId`, and never shown outside. */
final readonly class AccessRequestView
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public AccessRequestKind $kind,
        public string $name,
        public string $email,
        public ?string $company,
        public ?string $phone,
        public ?string $jobTitle,
        public PreferredContact $preferredContact,
        public ?AccessRequestNeed $need,
        public ?string $message,
        public string $locale,
        public AccessRequestStatus $status,
        public DateTimeImmutable $createdAt,
        public ?string $ownerId,
        public ?string $ownerName,
        public ?DateTimeImmutable $firstAttemptAt,
        public ?DateTimeImmutable $firstContactedAt,
        public ?DateTimeImmutable $lastContactAt,
        public ?DateTimeImmutable $nextFollowUpAt,
        public ?string $possibleDuplicateOf,
        public ?DateTimeImmutable $reviewedAt,
        public ?string $reviewedByName,
        public ?string $reviewNote,
        public ?string $clientMessage,
        public ?DateTimeImmutable $invitedAt,
        public ?DateTimeImmutable $invitationSharedAt,
        public ?DateTimeImmutable $invitationOpenedAt,
        public ?DateTimeImmutable $acceptedAt,
        public ?string $accountUserId,
        public ?DateTimeImmutable $firstLoginAt,
    ) {}
}
