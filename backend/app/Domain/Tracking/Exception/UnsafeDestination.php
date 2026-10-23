<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Exception;

use RuntimeException;

final class UnsafeDestination extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
