<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Contract\TrackingPolicyRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\Dispatch\ConsentPolicy;

/** A project's consent policy and how long raw tracking events are kept. A security-relevant choice, so it is audited. */
final readonly class SaveTrackingPolicy
{
    public function __construct(
        private TrackingPolicyRepository $policies,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $projectId, string $consentPolicy, int $retentionDays): void
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        $policy = ConsentPolicy::tryFrom($consentPolicy) ?? throw new TrackingRefused('Unknown consent policy.');

        if ($retentionDays < 30 || $retentionDays > 1095) {
            throw new TrackingRefused('Raw tracking events are kept between 30 and 1095 days.');
        }

        $this->policies->save($workspaceId, $projectId, $policy, $retentionDays, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingSettingsSaved,
            auditableType: 'project',
            auditableId: $projectId,
            summary: 'Changed the consent policy and retention',
            changes: ['consent_policy' => $policy->value, 'retention_days' => $retentionDays],
        ));
    }
}
