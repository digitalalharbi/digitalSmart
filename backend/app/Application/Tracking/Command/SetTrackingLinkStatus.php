<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\LinkStatus;

/** Pause, resume or archive a link. A paused link stops redirecting and keeps every event it recorded. */
final readonly class SetTrackingLinkStatus
{
    public function __construct(
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id, LinkStatus $status): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $link = $this->links->find($workspaceId, $this->visibility->visibleProjectIds(), $id)
            ?? throw ResourceNotFound::of('Tracking link');

        if ($link->status === $status) {
            return;
        }

        $this->links->setStatus($workspaceId, $id, $status);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingLinkSaved,
            auditableType: 'tracking_link',
            auditableId: $id,
            summary: sprintf('Tracking link "%s" is now %s', $link->name, $status->value),
            changes: ['from' => $link->status->value, 'to' => $status->value],
        ));
    }
}
