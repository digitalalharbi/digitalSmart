<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\Clock;
use App\Domain\Leads\Automation\LeadSla;
use App\Domain\Leads\Automation\SlaState;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use Throwable;

/**
 * Tell the people who answer requests when one is about to miss, or has missed, the response time — and when an invitation has
 * sat unaccepted for two days. Runs as the system. The text names no one; the centre's own de-duplication and cooldown stop it
 * being said every run.
 */
final readonly class RaiseAccessRequestAlerts
{
    private const int LOOKBACK_DAYS = 14;

    private const int INVITATION_PATIENCE_HOURS = 48;

    public function __construct(
        private AccessRequestRepository $requests,
        private Announce $announce,
        private Clock $clock,
    ) {}

    /** @return array{approaching: int, overdue: int, invitations: int} */
    public function __invoke(): array
    {
        $now = $this->clock->now();
        $raised = ['approaching' => 0, 'overdue' => 0, 'invitations' => 0];

        foreach ($this->requests->awaitingFirstAttempt($now->modify(sprintf('-%d days', self::LOOKBACK_DAYS))) as $row) {
            $view = $row['view'];
            $state = LeadSla::judge($view->createdAt, $view->firstAttemptAt, $view->firstContactedAt, $row['minutes'], $now)->state;

            if ($state !== SlaState::Approaching && $state !== SlaState::Overdue) {
                continue;
            }

            $overdue = $state === SlaState::Overdue;

            try {
                ($this->announce)(new NotificationDraft(
                    kind: $overdue ? NotificationKind::AccessRequestOverdue : NotificationKind::AccessRequestApproaching,
                    title: $overdue ? 'A request is past its response time' : 'A request is close to its response time',
                    subjectId: $view->id,
                    onlyUserId: $view->ownerId,
                    severity: $overdue ? NotificationSeverity::Critical : NotificationSeverity::Attention,
                    payload: ['sla_minutes' => $row['minutes'], 'kind' => $view->kind->value],
                    dedupeKey: ($overdue ? 'access_request_overdue:' : 'access_request_approaching:').$view->id,
                    cooldownHours: $overdue ? 12 : 6,
                ), $view->workspaceId);
                $raised[$overdue ? 'overdue' : 'approaching']++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach ($this->requests->invitationsPending($now->modify(sprintf('-%d hours', self::INVITATION_PATIENCE_HOURS))) as $view) {
            try {
                ($this->announce)(new NotificationDraft(
                    kind: NotificationKind::AccessInvitationPending,
                    title: 'An invitation has not been accepted',
                    subjectId: $view->id,
                    onlyUserId: $view->ownerId,
                    severity: NotificationSeverity::Attention,
                    dedupeKey: 'access_invitation_pending:'.$view->id,
                    cooldownHours: 24,
                ), $view->workspaceId);
                $raised['invitations']++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $raised;
    }
}
