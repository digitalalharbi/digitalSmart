<?php

declare(strict_types=1);

namespace App\Application\Reporting\Subscription;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * Pause, resume or remove a recipient.
 *
 * A recipient who unsubscribed is never resumed from here: they said stop, and only adding them again — with a new
 * consent note — writes to that address. Removing deletes the address and its delivery history with it.
 */
final readonly class ChangeReportSubscription
{
    public function __construct(
        private ReportRepository $reports,
        private ReportSubscriptionStore $subscriptions,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function pause(string $id): void
    {
        $subscription = $this->visible($id);

        if ($subscription->status === 'active') {
            $this->subscriptions->setStatus($id, 'paused');
            $this->record($subscription, 'paused');
        }
    }

    public function resume(string $id): void
    {
        $subscription = $this->visible($id);

        // Paused by the agency, or halted because the sender lost access — both are the agency's to restart.
        if ($subscription->status === 'paused' || $subscription->status === 'halted') {
            $this->subscriptions->setStatus($id, 'active');
            $this->record($subscription, 'resumed');
        }
    }

    public function remove(string $id): void
    {
        $subscription = $this->visible($id);

        $this->subscriptions->remove($subscription->workspaceId, $id);
        $this->record($subscription, 'removed');
    }

    private function visible(string $id): ReportSubscription
    {
        $this->guard->ensure(Permission::ReportsShare);

        $workspaceId = $this->actor->workspaceId()->value;
        $subscription = $this->subscriptions->find($workspaceId, $id) ?? throw ResourceNotFound::of('Report recipient');

        // Through the report, so a recipient on a report the caller cannot see is not found rather than forbidden.
        if ($this->reports->find($workspaceId, $this->visibility->visibleProjectIds(), $subscription->reportId) === null) {
            throw ResourceNotFound::of('Report recipient');
        }

        return $subscription;
    }

    private function record(ReportSubscription $subscription, string $what): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ReportRecipientChanged,
            auditableType: 'report_email_subscription',
            auditableId: $subscription->id,
            summary: sprintf('Email recipient %s (%s)', $what, $subscription->recipientEmail),
            changes: ['report_id' => $subscription->reportId, 'recipient_email' => $subscription->recipientEmail, 'change' => $what],
        ));
    }
}
