<?php

declare(strict_types=1);

namespace App\Application\Platform\DTO;

final readonly class CreatedWorkspace
{
    public function __construct(
        public string $workspaceId,
        public string $ownerUserId,
        public string $ownerEmail,
        /** Shown once, to the operator, when the owner's account was created here. Null when the owner already had an account. */
        public ?string $oneTimePassword,
    ) {}
}
