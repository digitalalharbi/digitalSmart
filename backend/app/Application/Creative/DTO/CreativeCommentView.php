<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\CommentVisibility;
use DateTimeImmutable;

final readonly class CreativeCommentView
{
    public function __construct(
        public string $id,
        public CommentVisibility $visibility,
        public string $body,
        public DateTimeImmutable $createdAt,
        public ?string $authorName = null,
        public ?int $versionNumber = null,
    ) {}
}
