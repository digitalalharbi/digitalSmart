<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\CommentVisibility;

final readonly class CommentInput
{
    public function __construct(
        public string $body,
        /** Required with no default. An internal note reaching a client ends a relationship. */
        public CommentVisibility $visibility,
        public ?string $versionId = null,
    ) {}
}
