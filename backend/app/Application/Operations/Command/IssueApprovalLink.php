<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ApprovalLinkDelivery;
use App\Application\Operations\Contract\ApprovalLinkRepository;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\Exception\InvalidApprovalTransition;
use DomainException;

/**
 * Send the person asking a one-time link to decide one pending approval from WhatsApp or email.
 *
 * Only to themselves, and only if they could decide it on the screen right now: the link is a shortcut to their own
 * authority, never a way to lend it. The secret goes to the channel and nowhere else — this returns no URL — and is
 * kept only as a keyed hash. Thirty minutes by default, never more than a day.
 */
final readonly class IssueApprovalLink
{
    public const int DEFAULT_MINUTES = 30;

    public const int MAX_MINUTES = 1440;

    public function __construct(
        private ApprovalRepository $approvals,
        private ApprovalLinkRepository $links,
        private ApprovalLinkDelivery $delivery,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
        private string $appKey,
        private string $baseUrl,
    ) {}

    /**
     * @param  'whatsapp'|'email'  $channel
     * @return array{delivery: string, expires_at: string}
     */
    public function __invoke(string $approvalId, string $channel, int $minutes = self::DEFAULT_MINUTES): array
    {
        $this->guard->ensure(Permission::ApprovalsDecide);

        $workspaceId = $this->actor->workspaceId()->value;
        $approval = $this->approvals->find($workspaceId, $this->visibility->visibleProjectIds(), $approvalId)
            ?? throw ResourceNotFound::of('Approval request');

        $second = $approval->kind->decidedWith();

        if ($second !== null) {
            $this->guard->ensure($second);
        }

        if ($approval->state !== ApprovalState::Pending) {
            throw InvalidApprovalTransition::alreadySettled($approval->state, ApprovalState::Approved);
        }

        if ($minutes < 5 || $minutes > self::MAX_MINUTES) {
            throw new DomainException('A link lasts between five minutes and a day.');
        }

        $now = $this->clock->now();
        $expires = $now->modify(sprintf('+%d minutes', $minutes));
        $token = ApprovalLinkToken::generate();
        $userId = $this->actor->userId()->value;

        $delivery = $this->delivery->deliver(
            $channel,
            $userId,
            rtrim($this->baseUrl, '/').'/approve/'.$token,
            $approval->title,
            $expires->format(DATE_ATOM),
        );

        $linkId = $this->links->create(
            $workspaceId, $approvalId, $userId, $userId, $channel,
            ApprovalLinkToken::hash($token, $this->appKey), $approval->fingerprint, $expires, $delivery, $now,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ApprovalLinkIssued,
            auditableType: 'approval_request',
            auditableId: $approvalId,
            summary: sprintf('One-time %s link for "%s"', $channel, $approval->title),
            changes: ['link_id' => $linkId, 'channel' => $channel, 'expires_at' => $expires->format(DATE_ATOM), 'delivery' => $delivery],
        ));

        return ['delivery' => $delivery, 'expires_at' => $expires->format(DATE_ATOM)];
    }
}
