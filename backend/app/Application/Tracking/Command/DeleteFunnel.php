<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FunnelRepository;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

final readonly class DeleteFunnel
{
    public function __construct(
        private FunnelRepository $funnels,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $funnel = $this->funnels->find($workspaceId, $this->visibility->visibleProjectIds(), $id) ?? throw ResourceNotFound::of('Funnel');

        $this->funnels->delete($workspaceId, $id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingFunnelSaved,
            auditableType: 'funnel',
            auditableId: $id,
            summary: sprintf('Deleted funnel "%s"', $funnel['name']),
            changes: ['project_id' => $funnel['project_id']],
        ));
    }
}
