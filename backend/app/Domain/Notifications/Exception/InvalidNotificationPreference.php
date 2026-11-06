<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exception;

use App\Domain\Shared\Exception\DomainException;

/** A preference the product cannot honour, refused with the reason rather than saved and silently ignored. */
final class InvalidNotificationPreference extends DomainException
{
    private string $reason = 'unknown_timezone';

    /** A digest scheduled in a zone that does not exist is a digest that never comes. */
    public static function unknownTimezone(string $timezone): self
    {
        return new self(sprintf(
            '"%s" is not a time zone this product knows. Choose one from the list, or leave it to follow the workspace.',
            $timezone,
        ));
    }

    /** Half a window, or one that starts and ends on the same hour, is either nothing or the whole day — neither was meant. */
    public static function quietHours(): self
    {
        $error = new self('Quiet hours need a start and an end, each an hour from 0 to 23, and the two must differ.');
        $error->reason = 'invalid_quiet_hours';

        return $error;
    }

    /** A snooze is between a quarter of an hour and a week: shorter is a mis-click, longer is switching notifications off. */
    public static function snooze(): self
    {
        $error = new self('A snooze lasts between 15 minutes and 7 days.');
        $error->reason = 'invalid_snooze';

        return $error;
    }

    public function errorCode(): string
    {
        return $this->reason;
    }
}
