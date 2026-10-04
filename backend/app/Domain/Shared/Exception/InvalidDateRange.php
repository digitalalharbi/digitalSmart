<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

final class InvalidDateRange extends DomainException
{
    public static function endsBeforeStart(): self
    {
        return new self('The end date cannot be before the start date.');
    }

    public function errorCode(): string
    {
        return 'invalid_date_range';
    }
}
