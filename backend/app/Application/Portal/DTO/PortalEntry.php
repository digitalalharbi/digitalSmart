<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Portal\PortalModule;
use App\Domain\Portal\PortalState;

/**
 * One client's portal, as its reader sees it.
 *
 * Carries the state **and** the modules, because a reader with nothing to read is owed a reason rather than an
 * empty list. An empty list renders as "nothing has happened yet"; the state says whether the portal was never set
 * up or deliberately switched off.
 */
final readonly class PortalEntry
{
    /**
     * @param  list<PortalModule>  $modules
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public PortalState $state,
        public array $modules,
        public bool $isPreview,
    ) {}

    public function allows(PortalModule $module): bool
    {
        return $this->state->allowsAccess() && in_array($module, $this->modules, true);
    }
}
