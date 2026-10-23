<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * What the visitor has said about marketing use of their visit.
 *
 * `not_required` is a *project policy* statement, not a legal conclusion this product reaches on its own: whoever
 * configures the project says whether their audience requires consent, and the policy says what follows.
 */
enum ConsentState: string
{
    case Unknown = 'unknown';
    case Granted = 'granted';
    case Denied = 'denied';
    case NotRequired = 'not_required';

    /** Whether advertising destinations may receive the event. Essential first-party recording is separate. */
    public function allowsMarketingDestinations(): bool
    {
        return $this === self::Granted || $this === self::NotRequired;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
