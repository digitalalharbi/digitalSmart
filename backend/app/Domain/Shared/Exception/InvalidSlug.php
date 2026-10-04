<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class InvalidSlug extends DomainException
{
    public static function forFormat(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a valid slug: use lowercase letters, numbers and single hyphens.',
            $value,
        ));
    }

    public static function forLength(string $value, int $max): self
    {
        return new self(sprintf('A slug must be between 1 and %d characters, got %d.', $max, strlen($value)));
    }

    public function errorCode(): string
    {
        return 'invalid_slug';
    }
}
