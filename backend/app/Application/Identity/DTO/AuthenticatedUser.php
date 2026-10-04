<?php

declare(strict_types=1);

namespace App\Application\Identity\DTO;

/** The session's own view of itself: who you are, where you are, what you may do. */
final readonly class AuthenticatedUser
{
    /**
     * @param  list<WorkspaceSummary>  $workspaces
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public string $locale,
        public string $timezone,
        public ?string $jobTitle,
        public ?string $avatarUrl,
        public array $workspaces,
        public ?WorkspaceSummary $activeWorkspace,
        public array $permissions,
    ) {}
}
