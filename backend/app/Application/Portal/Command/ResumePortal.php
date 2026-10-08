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
 * Switch a suspended portal back on.
 *
 * It resumes **exposing exactly what it exposed before**, which is why suspension does not clear the grants. The
 * audit entry names them, because "we turned it back on" and "we turned it back on including the revenue figures"
 * are different sentences, and the second is the one somebody will be asked about.
 */
final readonly class ResumePortal
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

        if ($current->state !== PortalState::Suspended) {
            throw InvalidPortalChange::notSuspended();
        }

        $portalId = $this->portals->idFor($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client portal');

        $this->portals->resume($portalId, $this->clock->now());

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::PortalResumed,
            auditableType: 'client_portal',
            auditableId: $portalId,
            summary: sprintf('Switched the portal for %s back on', $current->clientName),
            changes: [
                'client_id' => $clientId,
                'exposing' => array_map(
                    static fn ($exposure): string => $exposure->module->value,
                    $current->exposed(),
                ),
            ],
        ));

        return ($this->read)($clientId);
    }
}
