<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class InvalidIdentifier extends DomainException
{
    /** @param class-string $type */
    public static function forValue(string $type, string $value): self
    {
        $parts = explode('\\', $type);

        return new self(sprintf('"%s" is not a valid %s.', $value, end($parts)));
    }

    public function errorCode(): string
    {
        return 'invalid_identifier';
    }
}
