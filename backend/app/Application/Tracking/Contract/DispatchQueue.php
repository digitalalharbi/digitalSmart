<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

interface DispatchQueue
{
    /** Hand a dispatch to the background worker. Idempotent: running one twice is safe because a sent dispatch is final. */
    public function push(string $dispatchId): void;
}
