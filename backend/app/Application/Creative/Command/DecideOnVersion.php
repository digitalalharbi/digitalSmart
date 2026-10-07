<?php

declare(strict_types=1);

namespace App\Application\Creative\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\Exception\InvalidCreativeTransition;

/**
 * Approve a version, or send it back with a reason.
 *
 * On a **version**, never on a request — the one decision in this module that needs a separate permission,
 * because producing the work and signing it off are different acts and the separation is the only thing
 * that makes "approved" mean anything. A creative may upload all day and approves nothing.
 *
 * A rejection without a reason is refused by this use case, by the state machine and by a database CHECK.
 * Three layers for one rule, because the reason is the only record of what the reviewer objected to and the
 * next version is a guess without it.
 */
final readonly class DecideOnVersion
{
    public function __construct(
        private CreativeRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        string $versionId,
        ApprovalOutcome $outcome,
        ?string $reason = null,
    ): CreativeRequestView {
        $this->guard->ensure(Permission::CreativeApprove);

        $workspaceId = $this->actor->workspaceId()->value;

        $requestId = $this->requests->requestIdForVersion($workspaceId, $versionId)
            ?? throw ResourceNotFound::of('Creative version');

        $current = $this->requests->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Creative version');

        if ($outcome->needsReason() && ($reason === null || trim($reason) === '')) {
            throw InvalidCreativeTransition::changesNeedAReason($current->state);
        }

        $decided = $this->requests->decide(
            $workspaceId,
            $versionId,
            $outcome,
            $reason,
            $this->actor->userId()->value,
        );

        $version = null;

        foreach ($decided->versions as $candidate) {
            if ($candidate->id === $versionId) {
                $version = $candidate;
            }
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CreativeDecided,
            auditableType: 'creative_version',
            auditableId: $versionId,
            summary: sprintf(
                '%s version %d of "%s"',
                $outcome->label(),
                $version === null ? 0 : $version->number,
                $decided->title,
            ),
            changes: [
                /*
                 * The version number is the point of this entry. "Approved" with no version is the claim a
                 * client disputes three months later and nobody can settle.
                 */
                'version' => $version?->number,
                'outcome' => $outcome->value,
                'reason' => $reason,
            ],
        ));

        return $decided;
    }
}
