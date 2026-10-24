<?php

declare(strict_types=1);

namespace App\Application\Campaign\DTO;

use App\Domain\Advertising\Launch\ProviderCreatePlan;

/** One account's creation, as handed to a creator. No credential: only whether the connection is a sandbox. */
final readonly class CreationRequest
{
    public function __construct(
        public string $workspaceId,
        public string $name,
        public string $platformAccountId,
        public string $idempotencyReference,
        public ProviderCreatePlan $plan,
        public bool $sandbox,
    ) {}
}
