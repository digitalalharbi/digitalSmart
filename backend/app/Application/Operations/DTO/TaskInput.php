<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;

/** What a caller supplies to open a task. */
final readonly class TaskInput
{
    public function __construct(
        public string $title,
        public TaskPriority $priority,
        public ?string $description = null,
        public ?string $clientId = null,
        public ?string $projectId = null,
        public ?string $assigneeId = null,
        public ?DateTimeImmutable $dueOn = null,
        public ?TaskSubjectType $subjectType = null,
        public ?string $subjectId = null,
    ) {}

    /**
     * Whether the subject is a whole link.
     *
     * A type with no id, or an id with no type, is refused rather than dropped — silently discarding half
     * of what a caller sent is how a task ends up detached from the thing it was created for.
     */
    public function hasWholeSubject(): bool
    {
        return ($this->subjectType === null) === ($this->subjectId === null);
    }
}
