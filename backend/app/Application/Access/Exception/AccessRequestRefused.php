<?php

declare(strict_types=1);

namespace App\Application\Access\Exception;

use App\Domain\Access\AccessRequestStatus;
use DomainException;

final class AccessRequestRefused extends DomainException
{
    public function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function cannotMove(AccessRequestStatus $from, AccessRequestStatus $to): self
    {
        return new self(
            sprintf('A request that is %s cannot become %s.', $from->value, $to->value),
            'access_request_cannot_move',
        );
    }
}
