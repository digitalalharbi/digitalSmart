<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

use App\Domain\Portal\PortalModule;
use DateTimeImmutable;

/**
 * One module, and whether this client can read it.
 *
 * Carries the prerequisite alongside the decision, because the agency surface has to be able to say "switching
 * this on now would show them an empty page" *before* it is switched on. An empty module exposed to a client does
 * not read as "not connected yet" — it reads as the agency having done nothing.
 */
final readonly class ModuleExposure
{
    public function __construct(
        public PortalModule $module,
        public bool $isExposed,
        public ?DateTimeImmutable $exposedAt,
        /** The person who exposed it. Null when the account has since been deleted. */
        public ?string $exposedByName,
        /** Whether the workspace holds what this module needs to show anything. */
        public bool $isPrerequisiteMet,
    ) {}
}
