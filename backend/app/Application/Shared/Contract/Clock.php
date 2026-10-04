<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use DateTimeImmutable;

/** Injected time, so use cases that depend on "now" stay deterministic in tests. */
interface Clock
{
    public function now(): DateTimeImmutable;
}
