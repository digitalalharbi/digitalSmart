<?php

declare(strict_types=1);

namespace App\Domain\Automation;

enum RuleSeverity: string
{
    case Watch = 'watch';
    case NeedsAttention = 'needs_attention';
    case Critical = 'critical';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
