<?php

declare(strict_types=1);

namespace App\Application\Reporting\Command;

use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Switch a paused link back on at the same address. Never extends the expiry and never revives a revoked link.
 */
final readonly class ResumeShare
{
    public function __construct(
        private ReportShareStore $shares,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(string $shareId): void
    {
        $this->guard->ensure(Permission::ReportsShare);

        $resumed = $this->shares->resume($this->actor->workspaceId()->value, $shareId, $this->clock->now());

        if ($resumed === null) {
            throw ResourceNotFound::of('Share');
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportShareResumed,
            auditableType: 'report_share',
            auditableId: $shareId,
            summary: sprintf('Resumed a report link (%s)', $resumed->hint),
            changes: ['report_id' => $resumed->reportId, 'recipient_label' => $resumed->recipientLabel],
        ));
    }
}
