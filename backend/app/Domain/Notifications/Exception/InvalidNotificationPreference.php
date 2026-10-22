<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exception;

use App\Domain\Shared\Exception\DomainException;

/** A preference the product cannot honour, refused with the reason rather than saved and silently ignored. */
final class InvalidNotificationPreference extends DomainException
{
    /** A digest scheduled in a zone that does not exist is a digest that never comes. */
    public static function unknownTimezone(string $timezone): self
    {
        return new self(sprintf(
            '"%s" is not a time zone this product knows. Choose one from the list, or leave it to follow the workspace.',
            $timezone,
        ));
    }

    public function errorCode(): string
    {
        return 'unknown_timezone';
    }
}
