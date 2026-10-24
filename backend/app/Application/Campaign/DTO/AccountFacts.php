<?php

declare(strict_types=1);

namespace App\Application\Campaign\DTO;

/** What the product knows about one ad account at preflight time. No credential is part of it — only whether it is a sandbox. */
final readonly class AccountFacts
{
    public function __construct(
        public string $id,
        public string $provider,
        public string $name,
        /** The platform's own id for the account. */
        public string $platformAccountId,
        public ?string $currency,
        public ?string $timezone,
        public string $status,
        public bool $boundToProject,
        public string $connectionStatus,
        public bool $sandbox,
    ) {}
}
