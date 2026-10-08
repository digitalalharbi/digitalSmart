<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Portal\PortalState;
use DateTimeImmutable;

/**
 * A client's portal as the agency sees it: the state, every module, and who decided each.
 *
 * Deliberately returned for a client with **no portal row at all**, in state `NotConfigured` with every module
 * unexposed. The alternative — a 404 until somebody enables it — would make "has anybody set this up?" a question
 * answered by an error code, and the surface could not then show the one thing it exists to show: that the answer
 * is currently *nothing*.
 */
final readonly class PortalView
{
    /**
     * @param  list<ModuleExposure>  $modules
     */
    public function __construct(
        public string $clientId,
        public string $clientName,
        public PortalState $state,
        public array $modules,
        public ?DateTimeImmutable $enabledAt,
        public ?string $enabledByName,
        public ?DateTimeImmutable $suspendedAt,
        public ?string $suspendedByName,
        /** The agency's own note. Never sent to the client — see `PortalState::visitorMessage()`. */
        public ?string $suspendedReason,
    ) {}

    /** @return list<ModuleExposure> */
    public function exposed(): array
    {
        return array_values(array_filter($this->modules, static fn (ModuleExposure $m): bool => $m->isExposed));
    }

    /**
     * Modules exposed that cannot currently show anything.
     *
     * The figure the agency surface leads with, because it is the one that embarrasses somebody: a portal with
     * three modules on it and nothing behind two of them.
     *
     * @return list<ModuleExposure>
     */
    public function exposedButEmpty(): array
    {
        return array_values(array_filter(
            $this->modules,
            static fn (ModuleExposure $m): bool => $m->isExposed && ! $m->isPrerequisiteMet,
        ));
    }
}
