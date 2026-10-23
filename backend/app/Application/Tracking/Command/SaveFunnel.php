<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\FunnelRepository;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\Exception\InvalidFunnel;
use App\Domain\Tracking\Funnel\FunnelDefinition;

/** Define or change a project's funnel. The stages are checked against the closed list and the journey's order. */
final readonly class SaveFunnel
{
    public function __construct(
        private FunnelRepository $funnels,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $stages
     * @return array{id: string, project_id: string, name: string, stages: list<array<string, mixed>>}
     */
    public function __invoke(string $projectId, string $name, array $stages, ?string $id = null): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($this->links->clientOfProject($workspaceId, $visible, $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        if ($id !== null) {
            $existing = $this->funnels->find($workspaceId, $visible, $id) ?? throw ResourceNotFound::of('Funnel');

            if ($existing['project_id'] !== $projectId) {
                throw new TrackingRefused('A funnel cannot move to another project.');
            }
        }

        try {
            $parsed = FunnelDefinition::stagesFrom($stages);
        } catch (InvalidFunnel $e) {
            throw new TrackingRefused($e->getMessage(), 0, $e);
        }

        $saved = $this->funnels->save($workspaceId, $projectId, $id, $name, $parsed, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingFunnelSaved,
            auditableType: 'funnel',
            auditableId: $saved['id'],
            summary: sprintf('%s funnel "%s"', $id === null ? 'Created' : 'Changed', $name),
            changes: ['project_id' => $projectId, 'stages' => array_map(static fn (array $s): string => (string) $s['source'], $saved['stages'])],
        ));

        return $saved;
    }
}
