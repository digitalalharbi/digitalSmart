<?php

declare(strict_types=1);

namespace App\Application\Reporting\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\DTO\ReportDefinition;
use App\Application\Reporting\DTO\SavedReport;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Save a report, new or changed.
 *
 * The definition validates itself — see `ReportDefinition::assertValid()` — so a report that nobody could read or
 * trust is refused before it is stored rather than discovered when somebody opens it.
 *
 * One rule is here rather than on the DTO, because it needs the actor: a report may only be filed against a client
 * whose projects the actor can see. Otherwise somebody could build a report on a client they have no access to and
 * then not be able to open it, leaving a row nobody can read or delete.
 */
final readonly class SaveReport
{
    public function __construct(
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ReportDefinition $definition, ?string $reportId = null): SavedReport
    {
        $this->guard->ensure(Permission::ReportsManage);

        $definition->assertValid();

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if (
            $definition->clientId !== null
            && ! $this->reports->isClientVisible($workspaceId, $visible, $definition->clientId)
        ) {
            throw ResourceNotFound::of('Client');
        }

        if ($reportId !== null) {
            $this->reports->find($workspaceId, $visible, $reportId)
                ?? throw ResourceNotFound::of('Report');
        }

        $report = $reportId === null
            ? $this->reports->save($workspaceId, $definition, $this->actor->userId()->value)
            : $this->reports->update($workspaceId, $reportId, $definition);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportSaved,
            auditableType: 'report',
            auditableId: $report->id,
            summary: sprintf(
                '%s report "%s"',
                $reportId === null ? 'Created' : 'Changed',
                $definition->name,
            ),
            changes: [
                'window' => $definition->window->value,
                'comparison' => $definition->comparison->value,
                /*
                 * Recorded because it decides whether the agency's own commentary can appear. A report flipped from
                 * internal to client-safe is the change somebody will want to find.
                 */
                'audience' => $definition->audience->value,
                'sections' => count($definition->sections),
                'client_id' => $definition->clientId,
            ],
        ));

        return $report;
    }
}
