<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Contract\TrackingSettingsRepository;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Tracking\DataLayerMap;
use App\Domain\Tracking\Exception\InvalidDataLayerMap;

final readonly class SaveDataLayerMap
{
    public function __construct(
        private TrackingSettingsRepository $settings,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $names
     * @return array<string, string> the resolved map
     */
    public function __invoke(string $projectId, array $names): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        try {
            $resolved = DataLayerMap::resolve($names);
        } catch (InvalidDataLayerMap $e) {
            throw new TrackingRefused($e->getMessage(), 0, $e);
        }

        /* Only what differs from the default is stored, so a later change to the defaults reaches projects that never chose. */
        $overrides = array_diff_assoc($resolved, DataLayerMap::defaults());
        $this->settings->saveDataLayer($workspaceId, $projectId, $overrides, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::TrackingSettingsSaved,
            auditableType: 'project',
            auditableId: $projectId,
            summary: 'Changed the data layer event names',
            changes: ['overrides' => $overrides],
        ));

        return $resolved;
    }
}
