<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Shared\Contract\Clock;
use DateTimeImmutable;

final readonly class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
