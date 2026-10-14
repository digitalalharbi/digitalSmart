<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\Contract\CampaignAssignmentRepository;
use App\Application\Advertising\DTO\CampaignOwnershipView;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Exception\CampaignCannotJoinProject;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Place a campaign in a project, or return it to its account's bindings.
 *
 * **A campaign may only join a project its account is already bound to.** Without that rule an assignment
 * would be a second, quieter way to grant a project access to an external account — which is the one thing
 * `project_integration_bindings` exists to prevent, and the agency runs competing clients in one
 * installation.
 */
final readonly class AssignCampaignToProject
{
    public function __construct(
        private CampaignAssignmentRepository $campaigns,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $campaignId, ?string $projectId): CampaignOwnershipView
    {
        $this->guard->ensure(Permission::ProjectsUpdate);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $campaign = $this->campaigns->find($workspaceId, $visible, $campaignId);

        if ($campaign === null) {
            throw ResourceNotFound::of('Campaign');
        }

        if ($projectId !== null) {
            $candidates = array_column($campaign->candidateProjects, 'id');

            if (! in_array($projectId, $candidates, true)) {
                throw CampaignCannotJoinProject::unbound($campaignId, $projectId);
            }
        }

        $this->campaigns->assign($workspaceId, $campaignId, $projectId);

        $assigned = $this->campaigns->find($workspaceId, $visible, $campaignId);

        if ($assigned === null) {
            throw ResourceNotFound::of('Campaign');
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CampaignProjectAssigned,
            auditableType: 'campaign',
            auditableId: $campaignId,
            summary: $assigned->projectName === null
                ? sprintf('%s returned to its account’s bindings', $campaign->campaignName)
                : sprintf('%s assigned to %s', $campaign->campaignName, $assigned->projectName),
            changes: [
                'project_id' => ['from' => $campaign->projectId, 'to' => $projectId],
            ],
            context: ['ownership' => $assigned->ownership->value],
        ));

        return $assigned;
    }
}
