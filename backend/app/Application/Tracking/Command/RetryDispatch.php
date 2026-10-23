<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\DispatchQueue;
use App\Application\Tracking\Contract\ProviderDispatchStore;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * A person sends a failed or unknown dispatch again — with the same event id, so a provider that de-duplicates will not
 * count it twice. After an `unknown` outcome this is the only way it is ever retried, and the interface says why.
 */
final readonly class RetryDispatch
{
    public function __construct(
        private ProviderDispatchStore $dispatches,
        private DispatchQueue $queue,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $d = $this->dispatches->find($id);

        $visible = $this->visibility->visibleProjectIds();

        if ($d === null || $d['workspace_id'] !== $workspaceId || ($visible !== null && ! in_array($d['project_id'], $visible, true)) || ! $this->dispatches->resetForRetry($workspaceId, $id)) {
            throw ResourceNotFound::of('Dispatch');
        }

        $this->queue->push($id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingSettingsSaved,
            auditableType: 'provider_dispatch',
            auditableId: $id,
            summary: 'Retried a conversion dispatch',
            changes: ['provider' => $d['provider'], 'event' => $d['event'], 'event_id' => $d['event_id']],
        ));
    }
}
