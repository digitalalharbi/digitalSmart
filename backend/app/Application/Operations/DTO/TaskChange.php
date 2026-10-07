<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\TaskPriority;
use DateTimeImmutable;

/**
 * A change to a task that is not a state move.
 *
 * Every field is a tri-state: absent means "leave it", present means "set it to this", and present-and-null
 * means "clear it". PHP has no undefined, so the presence flags are explicit. The alternative — treating
 * null as "leave alone" — makes it impossible to clear a due date, and a board where a date can be set but
 * never removed accumulates deadlines nobody agreed to.
 */
final readonly class TaskChange
{
    private function __construct(
        public bool $changesTitle,
        public ?string $title,
        public bool $changesDescription,
        public ?string $description,
        public bool $changesPriority,
        public ?TaskPriority $priority,
        public bool $changesDueOn,
        public ?DateTimeImmutable $dueOn,
        public bool $changesProject,
        public ?string $projectId,
    ) {}

    public static function nothing(): self
    {
        return new self(false, null, false, null, false, null, false, null, false, null);
    }

    public function withTitle(string $title): self
    {
        return new self(
            true, $title,
            $this->changesDescription, $this->description,
            $this->changesPriority, $this->priority,
            $this->changesDueOn, $this->dueOn,
            $this->changesProject, $this->projectId,
        );
    }

    public function withDescription(?string $description): self
    {
        return new self(
            $this->changesTitle, $this->title,
            true, $description,
            $this->changesPriority, $this->priority,
            $this->changesDueOn, $this->dueOn,
            $this->changesProject, $this->projectId,
        );
    }

    public function withPriority(TaskPriority $priority): self
    {
        return new self(
            $this->changesTitle, $this->title,
            $this->changesDescription, $this->description,
            true, $priority,
            $this->changesDueOn, $this->dueOn,
            $this->changesProject, $this->projectId,
        );
    }

    public function withDueOn(?DateTimeImmutable $dueOn): self
    {
        return new self(
            $this->changesTitle, $this->title,
            $this->changesDescription, $this->description,
            $this->changesPriority, $this->priority,
            true, $dueOn,
            $this->changesProject, $this->projectId,
        );
    }

    public function withProject(?string $projectId): self
    {
        return new self(
            $this->changesTitle, $this->title,
            $this->changesDescription, $this->description,
            $this->changesPriority, $this->priority,
            $this->changesDueOn, $this->dueOn,
            true, $projectId,
        );
    }

    public function isEmpty(): bool
    {
        return ! $this->changesTitle
            && ! $this->changesDescription
            && ! $this->changesPriority
            && ! $this->changesDueOn
            && ! $this->changesProject;
    }

    /**
     * The fields this change touches, for the audit entry.
     *
     * @return array<string, mixed>
     */
    public function toAuditChanges(): array
    {
        $changes = [];

        if ($this->changesTitle) {
            $changes['title'] = $this->title;
        }

        if ($this->changesDescription) {
            $changes['description'] = $this->description;
        }

        if ($this->changesPriority) {
            $changes['priority'] = $this->priority?->value;
        }

        if ($this->changesDueOn) {
            // The cleared case is recorded as null rather than omitted: removing a deadline is an event.
            $changes['due_on'] = $this->dueOn?->format('Y-m-d');
        }

        if ($this->changesProject) {
            $changes['project_id'] = $this->projectId;
        }

        return $changes;
    }
}
