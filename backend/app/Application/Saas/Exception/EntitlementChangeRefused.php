<?php

declare(strict_types=1);

namespace App\Application\Saas\Exception;

use DomainException;

/** An operator asked for a plan or an override that cannot be recorded. Each refusal names what to change. */
final class EntitlementChangeRefused extends DomainException
{
    public static function needsOperatorAndReason(): self
    {
        return new self('Name the operator and give a reason: the workspace will ask why its screens changed.');
    }

    public static function unknownPlan(string $code): self
    {
        return new self(sprintf('There is no plan "%s".', $code));
    }

    public static function unknownKind(string $kind): self
    {
        return new self(sprintf('"%s" is neither "feature" nor "limit".', $kind));
    }

    public static function unknownKey(string $kind, string $key): self
    {
        return new self(sprintf('There is no %s "%s".', $kind, $key));
    }

    public static function badValue(string $kind, string $value): self
    {
        return new self($kind === 'feature'
            ? sprintf('A feature takes on, off or inherit, not "%s".', $value)
            : sprintf('A limit takes a whole number, unlimited or inherit, not "%s".', $value));
    }

    public static function everywhere(string $key): self
    {
        return new self(sprintf('"%s" is part of every plan and cannot be switched off.', $key));
    }
}
