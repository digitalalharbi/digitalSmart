<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\MessageAudience;
use DateTimeImmutable;

/** One message on a client request. */
final readonly class ClientRequestMessageView
{
    public function __construct(
        public string $id,
        public MessageAudience $audience,
        public string $body,
        public ?string $authorName,
        public bool $isFromClient,
        public DateTimeImmutable $createdAt,
    ) {}
}
