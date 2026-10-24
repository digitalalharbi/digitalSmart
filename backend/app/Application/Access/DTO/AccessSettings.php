<?php

declare(strict_types=1);

namespace App\Application\Access\DTO;

/** The workspace's promise about how soon a request is tried, and who gets requests nobody was given. */
final readonly class AccessSettings
{
    public const int DEFAULT_MINUTES = 60;

    public function __construct(
        public int $responseMinutes,
        public ?string $defaultOwnerId,
        public ?string $defaultOwnerName,
    ) {}
}
