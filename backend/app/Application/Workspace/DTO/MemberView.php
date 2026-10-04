<?php

declare(strict_types=1);

namespace App\Application\Workspace\DTO;

final readonly class MemberView
{
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $status,
        public string $roleSlug,
        public string $roleName,
        public ?string $jobTitle,
        public bool $isActive,
        public ?string $lastLoginAt,
        public ?string $joinedAt,
        public int $projectCount,
    ) {}
}
