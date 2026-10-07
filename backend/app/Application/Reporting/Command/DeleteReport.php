<?php

declare(strict_types=1);

namespace App\Application\Reporting\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Delete a report.
 *
 * Its snapshots and shares go with it, by cascade, and that is the right answer rather than a convenient one: a
 * snapshot is a frozen rendering *of this report*, and a share is a link *to it*. Keeping either after the report
 * is gone would leave a live link to a document nobody in the workspace can see or revoke.
 *
 * The audit entry records how many of each were destroyed, because "there was a link out to this" is the one thing
 * somebody needs to know afterwards and the only remaining record of it.
 */
final readonly class DeleteReport
{
    public function __construct(
        private ReportRepository $reports,
        private ReportShareStore $shares,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $reportId): void
    {
        $this->guard->ensure(Permission::ReportsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $report = $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        $shares = $this->shares->forReport($workspaceId, $reportId);
        $snapshots = $this->reports->snapshots($workspaceId, $reportId, 200);

        $this->reports->delete($workspaceId, $reportId);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportDeleted,
            auditableType: 'report',
            auditableId: $reportId,
            summary: sprintf('Deleted report "%s"', $report->definition->name),
            changes: [
                /*
                 * The only remaining record that links existed. "There was a link out to this" is exactly what
                 * somebody needs to know after the report itself is gone.
                 */
                'shares_destroyed' => count($shares),
                'snapshots_destroyed' => count($snapshots),
                'client_id' => $report->definition->clientId,
            ],
        ));
    }
}
