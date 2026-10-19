<?php

declare(strict_types=1);

namespace App\Domain\Automation\Exception;

use App\Domain\Shared\Exception\DomainException;

/** A rule asked for more than it may be given: an action beyond its trust, or a trust level that is not available. */
final class RuleNotAllowed extends DomainException
{
    public function errorCode(): string
    {
        return 'rule_not_allowed';
    }
}
