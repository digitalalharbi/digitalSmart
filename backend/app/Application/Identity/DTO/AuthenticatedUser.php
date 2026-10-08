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
        /**
         * True while this person is still using a password somebody else chose for them.
         *
         * Published because the interface has to act on it: every request except reading this session, changing
         * the password and signing out is refused until it is false, and a client that did not know would show a
         * reader a wall of refusals instead of the one thing they can do.
         */
        public bool $mustChoosePassword = false,
    ) {}
}
