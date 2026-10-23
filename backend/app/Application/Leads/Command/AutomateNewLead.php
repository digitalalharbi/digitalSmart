<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Leads\Automation\LeadAutomationRepository;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadView;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Operations\Contract\TaskRepository;
use App\Application\Operations\DTO\TaskInput;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\DTO\OwnerCandidate;
use App\Domain\Access\Permission;
use App\Domain\Leads\Automation\AssignmentMode;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * A tracked form just made a lead: do what its project said to do about it.
 *
 * There is no signed-in person here — a visitor submitted a form — so this runs as the system and takes its permissions from
 * the rules a project set, never from anybody's session. It is also built to be unable to hurt the lead: every step is its
 * own attempt, a step that fails is reported and the next still runs, and nothing here can undo the lead having arrived.
 *
 * It reuses what the product already has — the lead's owner, the task board, the notification centre — and adds no second
 * automation engine. What it must never do is name somebody who cannot open the record: the owner comes only from the
 * people who may be given this project's leads.
 */
final readonly class AutomateNewLead
{
    public function __construct(
        private LeadAutomationRepository $automation,
        private LeadRepository $leads,
        private TaskRepository $tasks,
        private OwnerDirectory $owners,
        private Announce $announce,
    ) {}

    public function __invoke(string $workspaceId, LeadView $lead, string $locale, DateTimeImmutable $now): void
    {
        if ($lead->projectId === null) {
            return;
        }

        $settings = $this->automation->settings($workspaceId, $lead->projectId);

        if (! $settings->enabled) {
            return;
        }

        $ownerId = null;

        try {
            $ownerId = $this->pickOwner($workspaceId, $lead->projectId, $settings->mode, $settings->ownerId);

            if ($ownerId !== null) {
                $this->leads->assign($workspaceId, $lead->id, $ownerId, null, $settings->mode->reason()->value);
            }
        } catch (Throwable $e) {
            report($e);
            $ownerId = null;
        }

        if ($settings->createTask) {
            try {
                $this->openTask($workspaceId, $lead, $settings->slaMinutes, $ownerId, $locale, $settings->taskTitleAr, $settings->taskTitleEn, $settings->taskPriority, $now);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($ownerId !== null && $settings->notifyOwner) {
            try {
                /* No name, no number, no message: the notification says a lead is waiting and where it came from. */
                ($this->announce)(new NotificationDraft(
                    kind: NotificationKind::LeadAssigned,
                    title: $lead->sourceDetail !== null && $lead->sourceDetail !== '' ? 'New lead: '.$lead->sourceDetail : 'New lead',
                    subjectId: $lead->id,
                    body: 'Nobody has answered this enquiry yet.',
                    onlyUserId: $ownerId,
                    aboutProjectId: $lead->projectId,
                    aboutClientId: $lead->clientId,
                    severity: NotificationSeverity::Attention,
                    dedupeKey: 'lead_assigned:'.$lead->id,
                    cooldownHours: 1,
                ), $workspaceId);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    private function pickOwner(string $workspaceId, string $projectId, AssignmentMode $mode, ?string $named): ?string
    {
        if ($mode === AssignmentMode::None) {
            return null;
        }

        if ($mode === AssignmentMode::RoundRobin) {
            return $this->automation->nextInRotation($workspaceId, $projectId);
        }

        /* A named owner is honoured only while they can still be given this project's leads. */
        if ($named === null) {
            return null;
        }

        $eligible = array_any(
            $this->automation->eligibleOwners($workspaceId, $projectId),
            static fn (OwnerCandidate $c): bool => $c->id === $named,
        );

        return $eligible ? $named : null;
    }

    private function openTask(
        string $workspaceId,
        LeadView $lead,
        ?int $slaMinutes,
        ?string $ownerId,
        string $locale,
        ?string $titleAr,
        ?string $titleEn,
        string $priority,
        DateTimeImmutable $now,
    ): void {
        $zone = new DateTimeZone($this->automation->timezone($workspaceId));
        $due = ($slaMinutes === null ? $now : $lead->receivedAt->modify(sprintf('+%d minutes', $slaMinutes)))->setTimezone($zone);
        $title = $locale === 'en'
            ? ($titleEn ?? $titleAr ?? 'Call the new lead')
            : ($titleAr ?? $titleEn ?? 'اتصل بالعميل المحتمل');

        /* The assignee must be able to open tasks; otherwise the task stands unassigned rather than naming somebody blind. */
        $assignee = $ownerId !== null && array_any(
            $this->owners->peopleWhoCanSee($workspaceId, $lead->clientId, Permission::TasksView),
            static fn (OwnerCandidate $c): bool => $c->id === $ownerId,
        ) ? $ownerId : null;

        $this->tasks->open($workspaceId, new TaskInput(
            title: $title,
            priority: TaskPriority::tryFrom($priority) ?? TaskPriority::High,
            description: $slaMinutes === null
                ? 'Created automatically when a tracked form made this lead.'
                : sprintf('Created automatically when a tracked form made this lead. Respond within %d minutes of its arrival.', $slaMinutes),
            clientId: $lead->clientId,
            projectId: $lead->projectId,
            assigneeId: $assignee,
            dueOn: new DateTimeImmutable($due->format('Y-m-d')),
            subjectType: TaskSubjectType::Lead,
            subjectId: $lead->id,
        ), null);
    }
}
