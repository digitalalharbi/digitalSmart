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
 * Switch a live link off, reversibly, and keep its address.
 *
 * Unlike revoking this needs no reason and is undone by `ResumeShare`. It only ever applies to a link that is live:
 * a revoked, expired, already-paused or foreign link is "not found" in one voice, so nothing is confirmed across a
 * tenant boundary and the moment a link was first paused is never moved.
 */
final readonly class PauseShare
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

        $paused = $this->shares->pause($this->actor->workspaceId()->value, $shareId, $this->clock->now());

        if ($paused === null) {
            throw ResourceNotFound::of('Share');
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportSharePaused,
            auditableType: 'report_share',
            auditableId: $shareId,
            summary: sprintf('Paused a report link (%s)', $paused->hint),
            changes: ['report_id' => $paused->reportId, 'recipient_label' => $paused->recipientLabel],
        ));
    }
}
