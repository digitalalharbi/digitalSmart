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
use App\Domain\Portal\PortalModule;
use App\Domain\Portal\PortalState;

/**
 * Set exactly which surfaces a client's portal exposes.
 *
 * The call is a **whole set**, not a toggle, because the question being answered is "what can this client see" and
 * a form that submits one change at a time can leave the answer half-applied. What it records, though, is one
 * entry per module that actually changed: an entry saying "the set is now performance, reports" cannot answer "when
 * did they get the revenue figures", and that is the question this trail is kept for.
 *
 * **Nothing is recorded when nothing changed.** Saving a form unchanged is not a disclosure, and a trail that
 * logged it would bury the five real entries in fifty.
 *
 * **It refuses a portal that is not switched on.** Choosing what a closed portal would expose is configuring a
 * disclosure nobody has authorised, and the resulting rows would be live the moment somebody resumed it.
 */
final readonly class SetPortalModules
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

    /**
     * @param  list<PortalModule>  $modules
     */
    public function __invoke(string $clientId, array $modules): PortalView
    {
        $this->guard->ensure(Permission::PortalManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->portals->find($workspaceId, $this->visibility->visibleProjectIds(), $clientId)
            ?? throw ResourceNotFound::of('Client');

        if ($current->state !== PortalState::Active) {
            throw ResourceNotFound::of('Client portal');
        }

        $portalId = $this->portals->idFor($workspaceId, $clientId)
            ?? throw ResourceNotFound::of('Client portal');

        $wanted = [];

        foreach ($modules as $module) {
            $wanted[$module->value] = $module;
        }

        $already = [];

        foreach ($current->exposed() as $exposure) {
            $already[$exposure->module->value] = $exposure->module;
        }

        $at = $this->clock->now();
        $actorId = $this->actor->userId()->value;

        foreach ($wanted as $value => $module) {
            if (isset($already[$value])) {
                continue;
            }

            if (! $this->portals->expose($workspaceId, $portalId, $module, $actorId, $at)) {
                continue;
            }

            $this->audit->record(new AuditEntry(
                workspaceId: $this->actor->workspaceId(),
                actorId: $this->actor->userId(),
                action: AuditAction::PortalModuleExposed,
                auditableType: 'client_portal',
                auditableId: $portalId,
                summary: sprintf('Exposed %s to %s', $module->label(), $current->clientName),
                changes: [
                    'client_id' => $clientId,
                    'module' => $module->value,
                    /*
                     * Whether the module could show anything at the moment it was exposed. An empty surface shown
                     * to a client reads as the agency having done nothing, and this is the entry that says
                     * somebody was told so before they turned it on.
                     */
                    'had_data_behind_it' => $this->prerequisiteMet($current, $module),
                ],
            ));
        }

        foreach ($already as $value => $module) {
            if (isset($wanted[$value])) {
                continue;
            }

            if (! $this->portals->withdraw($portalId, $module)) {
                continue;
            }

            $this->audit->record(new AuditEntry(
                workspaceId: $this->actor->workspaceId(),
                actorId: $this->actor->userId(),
                action: AuditAction::PortalModuleWithdrawn,
                auditableType: 'client_portal',
                auditableId: $portalId,
                summary: sprintf('Withdrew %s from %s', $module->label(), $current->clientName),
                changes: [
                    'client_id' => $clientId,
                    'module' => $module->value,
                ],
            ));
        }

        return ($this->read)($clientId);
    }

    private function prerequisiteMet(PortalView $view, PortalModule $module): bool
    {
        foreach ($view->modules as $exposure) {
            if ($exposure->module === $module) {
                return $exposure->isPrerequisiteMet;
            }
        }

        return false;
    }
}
