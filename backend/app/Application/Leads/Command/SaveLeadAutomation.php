<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Automation\LeadAutomationRepository;
use App\Application\Leads\Automation\LeadAutomationSettings;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Leads\Automation\LeadSla;

/**
 * Set what a project does with a new lead and how quickly it promises to answer.
 *
 * A named owner is checked against the people who may be given this project's leads: a setting that named somebody who
 * cannot open the record would put a name in the owner column of leads they can never see.
 */
final readonly class SaveLeadAutomation
{
    public function __construct(
        private LeadAutomationRepository $automation,
        private TrackingLinkRepository $projects,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(LeadAutomationSettings $settings): LeadAutomationSettings
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->projects->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $settings->projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        if ($settings->mode->needsOwner()) {
            $named = $settings->ownerId;

            if ($named === null) {
                throw new TrackingRefused('Choose who receives the leads.');
            }

            if (! array_any($this->automation->eligibleOwners($workspaceId, $settings->projectId), static fn (OwnerCandidate $c): bool => $c->id === $named)) {
                throw new TrackingRefused('That person cannot open this project’s leads, so they cannot be given them.');
            }
        }

        if ($settings->slaMinutes !== null && ($settings->slaMinutes < LeadSla::MIN_MINUTES || $settings->slaMinutes > LeadSla::MAX_MINUTES)) {
            throw new TrackingRefused('The response time is between one minute and seven days.');
        }

        if (! in_array($settings->taskPriority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new TrackingRefused('Unknown task priority.');
        }

        $this->automation->save($workspaceId, $settings, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingSettingsSaved,
            auditableType: 'project',
            auditableId: $settings->projectId,
            summary: 'Changed how a project handles new leads',
            changes: [
                'enabled' => $settings->enabled, 'mode' => $settings->mode->value, 'create_task' => $settings->createTask,
                'sla_minutes' => $settings->slaMinutes,
            ],
        ));

        return $this->automation->settings($workspaceId, $settings->projectId);
    }
}
