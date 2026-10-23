<?php

declare(strict_types=1);

namespace App\Domain\Leads\Automation;

/**
 * How a project hands a new lead to somebody.
 *
 * `none` leaves it unowned, which is a choice and not a default to be filled in. `specific` and `project_default` both name
 * one person — the first as a rule for this form's leads, the second as the person the project falls back on — and are kept
 * apart so the reason recorded on a lead says which one it was. `round_robin` shares leads across everybody who may be given
 * them, in a fixed order.
 */
enum AssignmentMode: string
{
    case None = 'none';
    case Specific = 'specific';
    case ProjectDefault = 'project_default';
    case RoundRobin = 'round_robin';

    public function needsOwner(): bool
    {
        return $this === self::Specific || $this === self::ProjectDefault;
    }

    public function reason(): AssignmentReason
    {
        return match ($this) {
            self::ProjectDefault => AssignmentReason::ProjectDefault,
            self::RoundRobin => AssignmentReason::RoundRobin,
            default => AssignmentReason::Automation,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
