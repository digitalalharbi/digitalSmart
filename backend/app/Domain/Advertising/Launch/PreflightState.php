<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

/**
 * What a preflight check found. Every state other than `ready` carries a reason and what fixes it; a launch plan is only as ready
 * as its worst check.
 */
enum PreflightState: string
{
    case Ready = 'ready';
    /** It can go ahead, and somebody should read why first. */
    case Warning = 'warning';
    /** Something the workspace can fix must be fixed first. */
    case Blocked = 'blocked';
    /** The connection holds sandbox credentials; real ones are needed. */
    case AwaitingCredentials = 'awaiting_credentials';
    /** Waiting on something only the platform or the owner can grant. */
    case BlockedExternal = 'blocked_external';
    /** The platform's documentation does not confirm it; nothing is attempted. */
    case Unsupported = 'unsupported';

    /** Higher is worse. */
    public function severity(): int
    {
        return match ($this) {
            self::Ready => 0,
            self::Warning => 1,
            self::AwaitingCredentials => 2,
            self::BlockedExternal => 3,
            self::Unsupported => 4,
            self::Blocked => 5,
        };
    }

    public function stopsCreation(): bool
    {
        return $this->severity() >= self::AwaitingCredentials->severity();
    }

    /** @param list<self> $states */
    public static function worst(array $states): self
    {
        $worst = self::Ready;

        foreach ($states as $state) {
            if ($state->severity() > $worst->severity()) {
                $worst = $state;
            }
        }

        return $worst;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
