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
use App\Domain\Reporting\ReportAudience;

/**
 * Copy a report's definition into a new report that belongs to the copier.
 *
 * What is copied is the definition only — window, comparison, filters, sections, audience, client. What is not: the
 * original's links (a copy is not shared with anyone), its snapshots and its history. The copy keeps the original's
 * audience and never widens it, and the internal note travels with an internal copy only: the note is the agency's own
 * commentary and a client-safe copy must not start life carrying it.
 *
 * Seen through the same visibility as opening the original, so a report the reader cannot see cannot be copied by its id.
 */
final readonly class DuplicateReport
{
    public function __construct(
        private ReportRepository $reports,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $reportId): SavedReport
    {
        $this->guard->ensure(Permission::ReportsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $original = $this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $reportId)
            ?? throw ResourceNotFound::of('Report');

        $source = $original->definition;

        $copy = new ReportDefinition(
            name: $source->name.' (copy)',
            window: $source->window,
            comparison: $source->comparison,
            audience: $source->audience,
            filters: $source->filters,
            sections: $source->sections,
            description: $source->description,
            clientId: $source->clientId,
            customFrom: $source->customFrom,
            customUntil: $source->customUntil,
            internalNote: $source->audience === ReportAudience::Internal ? $source->internalNote : null,
        );

        $copy->assertValid();

        $saved = $this->reports->save($workspaceId, $copy, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportSaved,
            auditableType: 'report',
            auditableId: $saved->id,
            summary: sprintf('Duplicated report "%s"', $source->name),
            changes: ['duplicated_from' => $reportId, 'audience' => $copy->audience->value, 'sections' => count($copy->sections)],
        ));

        return $saved;
    }
}
