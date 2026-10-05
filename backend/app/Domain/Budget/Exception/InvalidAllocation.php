<?php

declare(strict_types=1);

namespace App\Domain\Budget\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * An allocation that cannot sit where it was put.
 *
 * The governance hierarchy is the point of the feature: if the parts can add up to
 * more than the whole, or hang off nothing, then "approved budget" is decoration.
 */
final class InvalidAllocation extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_allocation';
    }

    public static function exceedsBudget(string $formattedTotal, string $formattedBudget): self
    {
        return new self(sprintf(
            'These allocations come to %s, which is more than the approved budget of %s.',
            $formattedTotal,
            $formattedBudget,
        ));
    }

    public static function exceedsParent(string $label, string $formattedTotal, string $formattedParent): self
    {
        return new self(sprintf(
            'The allocations under "%s" come to %s, which is more than the %s allocated to it.',
            $label,
            $formattedTotal,
            $formattedParent,
        ));
    }

    public static function needsAParent(string $level, string $parentLevel): self
    {
        return new self(sprintf(
            'A %s allocation has to sit under a %s allocation.',
            strtolower($level),
            strtolower($parentLevel),
        ));
    }

    public static function wrongParentLevel(string $level, string $expected, string $actual): self
    {
        return new self(sprintf(
            'A %s allocation has to sit under a %s allocation, not a %s one.',
            strtolower($level),
            strtolower($expected),
            strtolower($actual),
        ));
    }

    public static function childUnderDifferentProvider(string $label, string $childProvider, string $parentProvider): self
    {
        return new self(sprintf(
            '"%s" is a %s allocation sitting under a %s one. The platform total would then include another platform\'s spend.',
            $label,
            $childProvider,
            $parentProvider,
        ));
    }

    public static function rootMustBeAPlatform(string $level): self
    {
        return new self(sprintf(
            'A budget is split by platform first. A %s allocation cannot hang off the budget directly.',
            strtolower($level),
        ));
    }

    public static function amountMustBePositive(string $label): self
    {
        return new self(sprintf('"%s" needs an amount above zero. Remove it instead.', $label));
    }

    public static function needsAProvider(string $label): self
    {
        return new self(sprintf(
            '"%s" needs a platform. An allocation that does not say which platform it is for cannot be compared with any spend.',
            $label,
        ));
    }
}
