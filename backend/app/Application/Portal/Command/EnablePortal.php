<?php

declare(strict_types=1);

namespace App\Application\Portal\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Portal\Contract\PortalRepository;
use App\Application\Portal\DTO\PortalView;
use App\Application\Portal\Query\GetPortal;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Portal\Exception\InvalidPortalChange;
use App\Domain\Portal\PortalState;

/**
 * Switch a client's portal on.
 *
 * It opens **exposing nothing**. Every module is a separate decision, taken afterwards, with its own audit entry —
 * a portal that switched on with a sensible default set would make the first disclosure an act nobody performed.
 *
 * Resuming a suspended portal is a different act with a different entry, so this refuses one: "switched it back on"
 * and "set it up" are not the same sentence in a dispute, and the trail has to be able to tell them apart.
 */
final readonly class EnablePortal
{
    public function __construct(
        private PortalRepository $portals,
        private GetPortal $read,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(string $clientId): PortalView
    {
        $this->guard->ensure(Permission::PortalManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->portals->find($workspaceId, $this->visibility->visibleProjectIds(), $clientId)
            ?? throw ResourceNotFound::of('Client');

        if ($current->state === PortalState::Suspended) {
            throw InvalidPortalChange::alreadySuspended();
        }

        if ($current->state === PortalState::Active) {
            // Idempotent, and silent: nothing changed, so nothing is recorded.
            return $current;
        }

        $portalId = $this->portals->enable(
            $workspaceId,
            $clientId,
            $this->actor->userId()->value,
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::PortalEnabled,
            auditableType: 'client_portal',
            auditableId: $portalId,
            summary: sprintf('Switched on the portal for %s', $current->clientName),
            changes: [
                'client_id' => $clientId,
                /* Stated, so the entry proves the portal opened closed rather than relying on the next entry. */
                'exposed_modules' => [],
            ],
        ));

        return ($this->read)($clientId);
    }
}
