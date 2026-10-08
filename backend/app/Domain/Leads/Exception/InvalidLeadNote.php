<?php

declare(strict_types=1);

namespace App\Domain\Leads\Exception;

use App\Domain\Shared\Exception\DomainException;

final class InvalidLeadNote extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_lead_note';
    }

    public static function empty(): self
    {
        return new self('Write something. An empty note in a lead\'s history reads as something that was lost.');
    }
}
