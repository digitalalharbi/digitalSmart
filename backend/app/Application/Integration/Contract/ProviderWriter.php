<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Application\Integration\DTO\WriteRequest;
use App\Domain\Integration\WriteOutcome;

/**
 * Asking a provider to change something, and reading back what it says.
 *
 * One method, and it **never throws for a provider's refusal**. A platform saying no, a platform being
 * unavailable and a request whose fate is unknown are all outcomes a person has to be told about precisely, and
 * an exception would collapse them into "it failed" at the first `catch`. {@see WriteOutcome} carries which.
 *
 * Every implementation must read the entity back before reporting a confirmation. A provider returning 200 means
 * it accepted the request; it does not mean the campaign is paused.
 */
interface ProviderWriter
{
    /** Whether this writer is the one for a request. The registry asks; callers do not. */
    public function handles(WriteRequest $request): bool;

    public function execute(WriteRequest $request): WriteOutcome;
}
