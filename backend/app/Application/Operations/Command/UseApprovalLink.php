<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Operations\Contract\ApprovalLinkRepository;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\AuthorizationDenied;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
use App\Domain\Operations\ApprovalState;
use App\Domain\Workspace\WorkspaceId;
use ReflectionClass;
use Throwable;

/**
 * Decide an approval from a one-time link.
 *
 * In this order, and every refusal is the same answer to the caller so a token cannot be probed for which check it
 * failed: the token must be well-formed and known; the link must be unused and unexpired, and is claimed by one
 * conditional update before anything else happens — a second tap, or a link-preview robot that got there first,
 * finds it used. Then the decision is taken **as the link's person**, through `DecideApproval`: their membership,
 * their permissions (both of them, for spend), their project visibility and the proposal's fingerprint are all read
 * at this moment. A link issued before someone lost the right to decide, or before the proposal changed, decides
 * nothing. Every attempt on a known link is audited.
 */
final readonly class UseApprovalLink
{
    public function __construct(
        private ApprovalLinkRepository $links,
        private DecideApproval $decide,
        private ActorContextBinder $binder,
        private AuditRecorder $audit,
        private Clock $clock,
        private string $appKey,
    ) {}

    /** @param ApprovalState $outcome Approved or Declined. */
    public function __invoke(string $token, ApprovalState $outcome): ApprovalView
    {
        if (! ApprovalLinkToken::wellFormed($token)) {
            throw new ApprovalLinkRefused;
        }

        $link = $this->links->findByHash(ApprovalLinkToken::hash($token, $this->appKey))
            ?? throw new ApprovalLinkRefused;

        $now = $this->clock->now();

        if (! $this->links->claim($link['id'], $now)) {
            $this->trail($link, 'refused_used_or_expired');

            throw new ApprovalLinkRefused;
        }

        $this->binder->bind(new UserId($link['user_id']), new WorkspaceId($link['workspace_id']));

        try {
            $decided = ($this->decide)(
                $link['approval_id'],
                $outcome,
                $link['fingerprint'],
                $outcome === ApprovalState::Declined ? sprintf('Rejected from a one-time %s link.', $link['channel']) : null,
            );
        } catch (Throwable $failure) {
            $this->links->recordOutcome($link['id'], 'refused');
            $this->trail($link, $failure instanceof AuthorizationDenied ? 'refused_permission' : 'refused_'.(new ReflectionClass($failure))->getShortName());

            throw new ApprovalLinkRefused;
        } finally {
            $this->binder->clear();
        }

        $this->links->recordOutcome($link['id'], $outcome->value);
        $this->trail($link, $outcome->value);

        return $decided;
    }

    /** @param array{id: string, workspace_id: string, approval_id: string, user_id: string, channel: string} $link */
    private function trail(array $link, string $result): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($link['workspace_id']),
            actorId: new UserId($link['user_id']),
            action: AuditAction::ApprovalLinkUsed,
            auditableType: 'approval_request',
            auditableId: $link['approval_id'],
            summary: sprintf('One-time %s link: %s', $link['channel'], $result),
            changes: ['link_id' => $link['id'], 'result' => $result],
        ));
    }
}
