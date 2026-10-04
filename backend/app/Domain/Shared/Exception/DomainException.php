<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exception;

use RuntimeException;

/**
 * Base class for rule violations expressed by the domain.
 *
 * Domain exceptions carry no HTTP concern; the Interface layer decides how to
 * present them.
 */
abstract class DomainException extends RuntimeException
{
    /** Stable, machine-readable code clients can branch on. */
    abstract public function errorCode(): string;
}
