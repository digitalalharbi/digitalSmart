<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\DueHorizon;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskState;
use DateTimeImmutable;

/** One task, as everything that reads it needs it. */
final readonly class TaskView
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public TaskState $state,
        public TaskPriority $priority,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $assigneeId,
        public ?string $assigneeName,
        /** A date with no time in it. See the migration's note on why. */
        public ?DateTimeImmutable $dueOn,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
        public ?string $blockedReason,
        public ?string $cancelledReason,
        public ?DateTimeImmutable $stateChangedAt,
        public ?TaskSubjectView $subject,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Which due bucket this task is in, as of `$today`.
     *
     * Takes the moment from the caller rather than reading a clock, so every card on one board is placed
     * against one today. A board that read the clock per card would put two cards created a millisecond
     * apart on opposite sides of midnight.
     */
    public function horizon(DateTimeImmutable $today): DueHorizon
    {
        return DueHorizon::of($this->dueOn, $today, $this->state->isOpen());
    }

    public function isOverdue(DateTimeImmutable $today): bool
    {
        return $this->horizon($today) === DueHorizon::Overdue;
    }

    /**
     * How many days until it is due; negative once it is late, null with no date.
     *
     * Null rather than a large number, because "no due date" and "due in a very long time" are different
     * facts and a card should not imply the second when it means the first.
     */
    public function daysUntilDue(DateTimeImmutable $today): ?int
    {
        if ($this->dueOn === null) {
            return null;
        }

        $difference = $today->setTime(0, 0)->diff($this->dueOn->setTime(0, 0));

        return (int) $difference->days * ($difference->invert === 1 ? -1 : 1);
    }

    /**
     * How long the work took, in whole days, or null while it is unfinished.
     *
     * Measured from when somebody started it, not from when it was created: a task that sat in the backlog
     * for a month and was done in an hour took an hour, and reporting a month would make every estimate
     * drawn from this figure useless. Null when nobody recorded a start, because the duration is then
     * unknown rather than zero.
     */
    public function workingDays(): ?int
    {
        if ($this->completedAt === null || $this->startedAt === null) {
            return null;
        }

        return (int) $this->startedAt->setTime(0, 0)->diff($this->completedAt->setTime(0, 0))->days;
    }
}
