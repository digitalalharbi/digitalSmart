<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use RuntimeException;

/** One answer for every refusal, so the reason a link failed cannot be learned from outside. */
final class ApprovalLinkRefused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This link cannot be used. Open the approval in digitalSmart instead.');
    }
}
