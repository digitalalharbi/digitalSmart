<?php

declare(strict_types=1);

namespace App\Domain\Portal\Exception;

use DomainException;

/**
 * A change to a client's portal that this product refuses to make.
 *
 * Carries a `reasonCode` rather than reusing `Exception::$code`, which is a non-readonly int on the base class and
 * cannot be redeclared as a string.
 */
final class InvalidPortalChange extends DomainException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function suspensionNeedsReason(): self
    {
        return new self(
            'Switching off a client\'s portal needs a reason: it is the record somebody asks for later.',
            'suspension_needs_reason',
        );
    }

    public static function alreadySuspended(): self
    {
        return new self('This portal is already switched off.', 'already_suspended');
    }

    public static function notSuspended(): self
    {
        return new self('This portal is not switched off, so there is nothing to resume.', 'not_suspended');
    }

    public static function moduleUnknown(string $module): self
    {
        return new self(
            sprintf('"%s" is not a surface this product can expose to a client.', $module),
            'module_unknown',
        );
    }
}
