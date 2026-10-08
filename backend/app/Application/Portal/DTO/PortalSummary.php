<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Portal\PortalState;

/** One row of the agency's list: every visible client, and what their portal currently exposes. */
final readonly class PortalSummary
{
    public function __construct(
        public string $clientId,
        public string $clientName,
        public PortalState $state,
        public int $exposedCount,
        /** Of the exposed modules, how many cannot show anything yet. */
        public int $emptyCount,
    ) {}
}
