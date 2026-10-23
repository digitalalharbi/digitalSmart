<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Dispatch;

use App\Domain\Tracking\ConsentState;

/**
 * What a project says about sending its visitors' conversions to advertising platforms.
 *
 * This product makes no legal assumption for anyone. `required` is the cautious default: a conversion is dispatched only
 * for a visit that granted consent. `not_required` is the project owner's statement that their audience and jurisdiction do
 * not require it; a visitor who actively denied consent is still never sent.
 */
enum ConsentPolicy: string
{
    case Required = 'required';
    case NotRequired = 'not_required';

    public function allows(ConsentState $state): bool
    {
        return match ($this) {
            self::Required => $state === ConsentState::Granted || $state === ConsentState::NotRequired,
            self::NotRequired => $state !== ConsentState::Denied,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
