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
use App\Domain\Reporting\Exception\InvalidShare;

/**
 * Turn a link off, now.
 *
 * Immediately rather than at the next expiry: somebody revokes a link because it went to the wrong address, and
 * "it will stop working next month" is not an answer to that.
 *
 * A reason is required. It is the only record of what went wrong with a link, and the question asked afterwards is
 * always "why was this turned off" rather than "when".
 */
final readonly class RevokeShare
{
    public function __construct(
        private ReportShareStore $shares,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(string $shareId, ?string $reason): void
    {
        $this->guard->ensure(Permission::ReportsShare);

        if ($reason === null || trim($reason) === '') {
            throw InvalidShare::revocationNeedsAReason();
        }

        $revoked = $this->shares->revoke(
            $this->actor->workspaceId()->value,
            $shareId,
            trim($reason),
            $this->clock->now(),
        );

        if ($revoked === null) {
            /*
             * Either it does not exist, belongs to another workspace, or was already revoked. The same answer for
             * all three: confirming that a link exists elsewhere would itself cross a tenant boundary, and
             * re-revoking must not move the moment it stopped working.
             */
            throw ResourceNotFound::of('Share');
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportShareRevoked,
            auditableType: 'report_share',
            auditableId: $shareId,
            summary: sprintf('Turned off a report link (%s)', $revoked->hint),
            changes: [
                'reason' => trim($reason),
                'report_id' => $revoked->reportId,
                /*
                 * How much was read before it was turned off. The figure somebody needs when they are working out
                 * what a recipient could have seen, and the only record of it once the link is dead.
                 */
                'views_before_revocation' => $revoked->viewCount,
                'recipient_label' => $revoked->recipientLabel,
            ],
        ));
    }
}
