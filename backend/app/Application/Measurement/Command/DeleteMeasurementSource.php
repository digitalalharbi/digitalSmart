<?php

declare(strict_types=1);

namespace App\Application\Measurement\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Stop measuring through something.
 *
 * The observed counts go with it, by the foreign key. That is the right behaviour and it is
 * worth being explicit about: those counts only ever meant anything *as read through this
 * source*, and keeping orphans would leave a project's history including events from a
 * property nobody can look up any more.
 */
final readonly class DeleteMeasurementSource
{
    public function __construct(
        private MeasurementSourceRepository $sources,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->sources->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Measurement source');
        }

        $this->sources->delete($workspaceId, $id);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::MeasurementSourceDeleted,
            auditableType: 'measurement_source',
            auditableId: $id,
            summary: sprintf('Removed measurement source "%s"', $existing->name),
            changes: ['kind' => $existing->kind->value, 'external_id' => $existing->externalId],
        ));
    }
}
