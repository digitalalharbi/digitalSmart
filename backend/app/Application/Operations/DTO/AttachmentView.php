<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\AttachmentType;
use App\Domain\Operations\MessageAudience;
use DateTimeImmutable;

/**
 * One file on a request.
 *
 * `storedName` is on this object because the download needs it, and it is **not serialised**: a reader has no
 * use for it and publishing it would turn a generated name into a name somebody can guess at. The id is what the
 * API addresses.
 */
final readonly class AttachmentView
{
    public function __construct(
        public string $id,
        public MessageAudience $audience,
        public string $originalName,
        public string $storedName,
        public AttachmentType $type,
        public int $byteSize,
        public string $checksum,
        public ?string $uploadedByName,
        public bool $isFromClient,
        public DateTimeImmutable $uploadedAt,
    ) {}
}
