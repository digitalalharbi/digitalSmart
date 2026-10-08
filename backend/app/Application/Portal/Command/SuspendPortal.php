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
 * Switch a client's portal off, keeping the record of what it exposed.
 *
 * **It takes effect on the next request.** Somebody suspends a portal because something should stop being readable
 * now — an engagement ended, a figure turned out to be wrong, a dispute started — and a change that waited for a
 * billing boundary would be useless for every one of those.
 *
 * **The module grants are left in place.** They are what the portal goes back to when it resumes, and they are the
 * answer to "what could they see while it was on". Clearing them would make resuming an act of reconstruction from
 * somebody's memory of a meeting.
 *
 * **The reason is required and internal.** Required because a portal switched off with no reason is a row nobody
 * can act on; internal because "payment overdue" is a commercial conversation, and a sign-in screen is the worst
 * possible place to have it. The client is told to make contact, which is the outcome either way.
 */
final readonly class SuspendPortal
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

    public function __invoke(string $clientId, string $reason): PortalView
    {
        $this->guard->ensure(Permission::PortalManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->portals->find($workspaceId, $this->visibility->visibleProjectIds(), $clientId)
            ?? throw ResourceNotFound::of('Client');

        if ($current->state === PortalState::NotConfigured) {
            throw ResourceNotFound::of('Client portal');
        }

        if ($current->state === PortalState::Suspended) {
            throw InvalidPortalChange::alreadySuspended();
        }

        $trimmed = trim($reason);

        if ($trimmed === '') {
            throw InvalidPortalChange::suspensionNeedsReason();
        }

        $portalId = $this->portals->idFor($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client portal');

        $this->portals->suspend($portalId, $trimmed, $this->actor->userId()->value, $this->clock->now());

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::PortalSuspended,
            auditableType: 'client_portal',
            auditableId: $portalId,
            summary: sprintf('Switched off the portal for %s', $current->clientName),
            changes: [
                'client_id' => $clientId,
                'reason' => $trimmed,
                /*
                 * What stopped being readable, named rather than counted. "Three modules" does not answer the
                 * question this entry is kept for.
                 */
                'was_exposing' => array_map(
                    static fn ($exposure): string => $exposure->module->value,
                    $current->exposed(),
                ),
            ],
        ));

        return ($this->read)($clientId);
    }
}
