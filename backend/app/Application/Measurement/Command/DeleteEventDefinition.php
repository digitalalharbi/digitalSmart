<?php

declare(strict_types=1);

namespace App\Application\Measurement\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\Contract\EventDefinitionRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

final readonly class DeleteEventDefinition
{
    public function __construct(
        private EventDefinitionRepository $definitions,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $id): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $existing = $this->definitions->find($workspaceId, $this->visibility->visibleProjectIds(), $id);

        if ($existing === null) {
            throw ResourceNotFound::of('Conversion definition');
        }

        $this->definitions->delete($workspaceId, $id);

        /*
         * Recorded with what it used to count, because deleting a conversion changes every
         * historical report that referenced it and the audit trail is the only place that
         * remains legible afterwards.
         */
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ConversionDeleted,
            auditableType: 'event_definition',
            auditableId: $id,
            summary: sprintf('Deleted conversion "%s"', $existing->name),
            changes: [
                'normalized_event' => $existing->normalizedEvent->value,
                'was_primary' => $existing->isPrimary,
                'mapped_names' => array_map(
                    static fn (object $mapping): string => $mapping->rawEventName,
                    $existing->mappings,
                ),
            ],
        ));
    }
}
