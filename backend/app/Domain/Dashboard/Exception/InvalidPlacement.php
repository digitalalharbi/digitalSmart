<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * A widget placement the grid cannot hold.
 *
 * Raised rather than clamped. Clamping would move a widget the reader deliberately
 * placed, and silently placing one off-grid would remove it from the dashboard with
 * nothing to say it was ever there.
 */
final class InvalidPlacement extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_widget_placement';
    }
}
