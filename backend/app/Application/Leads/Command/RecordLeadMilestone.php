<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\Service\LeadProgressAnnouncer;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Service\ConversionDispatcher;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Tracking\TrackingEventName;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Say that a lead booked an appointment, or became an opportunity.
 *
 * Two moments the pipeline's stages do not have words for, and that a funnel needs. They are events in the lead's history
 * with their author and time, and they do not move the stage: a human still owns that.
 */
final readonly class RecordLeadMilestone
{
    public const array TYPES = ['appointment_booked', 'opportunity_created'];

    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
        private AuditRecorder $audit,
        private ConversionDispatcher $conversions,
        private LeadProgressAnnouncer $progress,
    ) {}

    public function __invoke(string $leadId, string $type, ?string $note = null, ?DateTimeImmutable $at = null): void
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $lead = $this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId);

        if ($lead === null) {
            throw ResourceNotFound::of('Lead');
        }

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unknown milestone.');
        }

        /* Never in the future: a funnel that counted something that has not happened would be counting a plan. */
        $when = $at !== null && $at <= $this->clock->now() ? $at : $this->clock->now();

        $this->leads->recordMilestone($workspaceId, $leadId, $type, $this->actor->userId()->value, $note !== null ? mb_substr(trim($note), 0, 500) : null, $when);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::LeadStageChanged,
            auditableType: 'lead',
            auditableId: $leadId,
            summary: $type === 'appointment_booked' ? 'Recorded an appointment' : 'Recorded an opportunity',
            changes: ['milestone' => $type],
        ));

        $this->conversions->forLead($workspaceId, $leadId, TrackingEventName::from($type), $when);

        if ($type === 'appointment_booked') {
            $this->progress->announce($lead, NotificationKind::LeadAppointmentBooked);
        }
    }
}
