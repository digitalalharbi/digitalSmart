<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class InvalidStatusTransition extends DomainException
{
    public static function between(string $entity, string $from, string $to): self
    {
        return new self(sprintf('A %s cannot move from %s to %s.', $entity, $from, $to));
    }

    public function errorCode(): string
    {
        return 'invalid_status_transition';
    }
}
