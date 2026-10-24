<?php

declare(strict_types=1);

namespace App\Application\Saas\Exception;

use App\Domain\Saas\SubscriptionStatus;
use RuntimeException;

/** The workspace is suspended or cancelled: everything can be read and nothing changed until it is resumed. */
final class WorkspaceNotWritable extends RuntimeException
{
    public function __construct(public readonly SubscriptionStatus $status, public readonly ?string $reason)
    {
        parent::__construct($status === SubscriptionStatus::Cancelled
            ? 'This workspace has ended. Everything in it can still be read and exported; nothing can be changed.'
            : 'This workspace is paused. Everything in it can still be read; nothing can be changed until it is resumed. Contact Smart Code.');
    }
}
