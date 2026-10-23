<?php

declare(strict_types=1);

namespace App\Application\Leads\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Automation\FollowUpRow;
use App\Application\Leads\Automation\LeadAutomationRepository;
use App\Application\Leads\Automation\LeadAutomationSettings;
use App\Application\Leads\Automation\LeadFollowUpReadModel;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Domain\Access\Permission;
use App\Domain\Leads\Automation\LeadSla;
use App\Domain\Leads\Automation\SlaState;
use DateTimeImmutable;
use DateTimeZone;

/**
 * What a project's team still has to deal with, grouped the way people work through it.
 *
 * Groups: new (nobody has tried), approaching and overdue (against the promised response time), attempted (somebody tried and
 * has not got through), no response (tried more than a day ago and still not through) and qualified (worth pursuing, not yet
 * closed). A lead is in exactly one group. This is a work queue, not a CRM: it shows what to do next and who holds it.
 */
final readonly class GetLeadFollowUp
{
    private const int ITEMS_PER_GROUP = 25;

    private const int NO_RESPONSE_AFTER_HOURS = 24;

    public function __construct(
        private LeadFollowUpReadModel $read,
        private LeadAutomationRepository $automation,
        private TrackingLinkRepository $projects,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array{settings: LeadAutomationSettings, owners: list<OwnerCandidate>, timezone: string, groups: array<string, array{count: int, items: list<array<string, mixed>>}>} */
    public function __invoke(string $projectId): array
    {
        $this->guard->ensure(Permission::LeadsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($this->projects->clientOfProject($workspaceId, $visible, $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        $now = $this->clock->now();
        $settings = $this->automation->settings($workspaceId, $projectId);
        $zone = $this->automation->timezone($workspaceId);
        $names = ['new', 'approaching', 'overdue', 'attempted', 'no_response', 'qualified'];
        $counts = array_fill_keys($names, 0);
        /** @var array<string, list<array<string, mixed>>> $items */
        $items = array_fill_keys($names, []);

        foreach ($this->read->openLeads($workspaceId, $visible, $projectId, 500) as $row) {
            [$group, $item] = $this->place($row, $settings->slaMinutes, $now, $zone);

            if ($group === null) {
                continue;
            }

            $counts[$group]++;

            if (count($items[$group]) < self::ITEMS_PER_GROUP) {
                $items[$group][] = $item;
            }
        }

        $groups = [];

        foreach ($names as $name) {
            $groups[$name] = ['count' => $counts[$name], 'items' => $items[$name]];
        }

        return [
            'settings' => $settings,
            'owners' => $this->automation->eligibleOwners($workspaceId, $projectId),
            'timezone' => $zone,
            'groups' => $groups,
        ];
    }

    /** @return array{0: ?string, 1: array<string, mixed>} */
    private function place(FollowUpRow $row, ?int $sla, DateTimeImmutable $now, string $zone): array
    {
        $state = $sla === null ? null : LeadSla::judge($row->receivedAt, $row->firstAttemptAt, $row->firstContactedAt, $sla, $now);
        $item = [
            'id' => $row->leadId,
            'name' => $row->name,
            'source' => $row->sourceDetail,
            'stage' => $row->stage,
            'owner' => $row->ownerId === null ? null : ['id' => $row->ownerId, 'name' => $row->ownerName],
            'assignment_reason' => $row->assignmentReason,
            'received_at' => $row->receivedAt->format(DATE_ATOM),
            'first_attempt_at' => $row->firstAttemptAt?->format(DATE_ATOM),
            'first_contacted_at' => $row->firstContactedAt?->format(DATE_ATOM),
            'sla_state' => $state?->state->value,
            'due_at' => $state?->dueAt->setTimezone(new DateTimeZone($zone))->format(DATE_ATOM),
            'sla_met' => $state?->met,
        ];

        if ($row->stage === 'qualified') {
            return ['qualified', $item];
        }

        if ($row->firstContactedAt !== null) {
            return [null, $item];
        }

        if ($row->firstAttemptAt === null) {
            return [match ($state?->state) {
                SlaState::Overdue => 'overdue',
                SlaState::Approaching => 'approaching',
                default => 'new',
            }, $item];
        }

        $quiet = $now->getTimestamp() - $row->firstAttemptAt->getTimestamp() >= self::NO_RESPONSE_AFTER_HOURS * 3600;

        return [$quiet ? 'no_response' : 'attempted', $item];
    }
}
