<?php

declare(strict_types=1);

namespace App\Application\Reporting\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\SnapshotSerialiser;
use App\Application\Reporting\Query\RenderReport;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Freeze a report as it is now.
 *
 * A snapshot is **stored output**, and taking one is the only way this product can answer "the report said 1.2
 * million" three months later. A live link recomputes; both are legitimate, and a visitor is always told which they
 * are looking at.
 *
 * The snapshot is rendered **as the report's own audience**. A client-safe report snapshots without the agency's
 * commentary, because a frozen payload is the thing a share link serves — and a payload that contained an internal
 * note would be one filter away from showing it. The note is not written, not written-and-hidden.
 */
final readonly class TakeSnapshot
{
    public function __construct(
        private ReportRepository $reports,
        private RenderReport $render,
        private SnapshotSerialiser $serialiser,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(string $reportId, ?string $label = null): string
    {
        $this->guard->ensure(Permission::ReportsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $report = $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        $rendered = ($this->render)($workspaceId, $report);
        $at = $this->clock->now();

        $snapshotId = $this->reports->takeSnapshot(
            $workspaceId,
            $reportId,
            $rendered->period,
            $this->serialiser->toArray($rendered),
            $rendered->source,
            $label,
            $this->actor->userId()->value,
            $at,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportSnapshotTaken,
            auditableType: 'report_snapshot',
            auditableId: $snapshotId,
            summary: sprintf('Took a snapshot of "%s"', $report->definition->name),
            changes: [
                'report_id' => $reportId,
                'period_from' => $rendered->period->start->format('Y-m-d'),
                'period_until' => $rendered->period->end->format('Y-m-d'),
                /*
                 * The source state at the moment it was frozen. A snapshot taken from sandbox fixtures is a sandbox
                 * snapshot forever, and the entry says so without having to parse the payload.
                 */
                'source' => $rendered->source->value,
                'audience' => $rendered->audience->value,
                'label' => $label,
            ],
        ));

        return $snapshotId;
    }
}
