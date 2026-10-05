<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class CurrencyMismatch extends DomainException
{
    public static function between(string $first, string $second): self
    {
        return new self(sprintf(
            'Cannot combine %s and %s. Convert with an explicit rate rather than summing across currencies.',
            $first,
            $second,
        ));
    }

    public function errorCode(): string
    {
        return 'currency_mismatch';
    }
}
