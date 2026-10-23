<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Leads\Automation\LeadFollowUpReadModel;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\Clock;
use App\Domain\Leads\Automation\LeadSla;
use App\Domain\Leads\Automation\SlaState;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use Throwable;

/**
 * Tell the right people when a lead is about to miss, or has missed, the response time its project promised.
 *
 * Runs as the system, on a schedule. The audience is the lead's owner when it has one and otherwise everybody who may read the
 * project's leads. One notification per lead per state: the centre's own de-duplication and cooldown stop it being said every
 * run, and a worse state is allowed through a cooldown a milder one set. The text names no one and carries no contact detail.
 */
final readonly class RaiseLeadSlaAlerts
{
    /** Leads older than this are no longer a response-time matter; the queue's "no response" group is. */
    private const int LOOKBACK_DAYS = 3;

    public function __construct(
        private LeadFollowUpReadModel $read,
        private Announce $announce,
        private Clock $clock,
    ) {}

    /** @return array{approaching: int, overdue: int} */
    public function __invoke(): array
    {
        $now = $this->clock->now();
        $raised = ['approaching' => 0, 'overdue' => 0];

        foreach ($this->read->awaitingFirstAttempt($now->modify(sprintf('-%d days', self::LOOKBACK_DAYS))) as $row) {
            if ($row->slaMinutes === null) {
                continue;
            }

            $state = LeadSla::judge($row->receivedAt, $row->firstAttemptAt, $row->firstContactedAt, $row->slaMinutes, $now)->state;

            if ($state !== SlaState::Approaching && $state !== SlaState::Overdue) {
                continue;
            }

            $overdue = $state === SlaState::Overdue;

            try {
                ($this->announce)(new NotificationDraft(
                    kind: $overdue ? NotificationKind::LeadSlaOverdue : NotificationKind::LeadSlaApproaching,
                    title: $overdue ? 'A lead is past its response time' : 'A lead is close to its response time',
                    subjectId: $row->leadId,
                    body: $row->sourceDetail,
                    onlyUserId: $row->ownerId,
                    aboutProjectId: $row->projectId,
                    aboutClientId: $row->clientId,
                    severity: $overdue ? NotificationSeverity::Critical : NotificationSeverity::Attention,
                    payload: ['sla_minutes' => $row->slaMinutes],
                    dedupeKey: ($overdue ? 'lead_sla_overdue:' : 'lead_sla_approaching:').$row->leadId,
                    cooldownHours: $overdue ? 12 : 6,
                ), $row->workspaceId);
                $raised[$overdue ? 'overdue' : 'approaching']++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $raised;
    }
}
