<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class InvalidEmail extends DomainException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('"%s" is not a valid e-mail address.', $value));
    }

    public function errorCode(): string
    {
        return 'invalid_email';
    }
}
