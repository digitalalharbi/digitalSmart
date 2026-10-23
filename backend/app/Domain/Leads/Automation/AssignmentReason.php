<?php

declare(strict_types=1);

namespace App\Domain\Leads\Automation;

/** Why a lead is in somebody's name — recorded so a pipeline review can tell a choice from a rota. */
enum AssignmentReason: string
{
    case Manual = 'manual';
    case ProjectDefault = 'project_default';
    case RoundRobin = 'round_robin';
    case Automation = 'automation';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
