<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\TaskSubjectType;

/**
 * What a task is about, resolved at read time.
 *
 * `label` is null when the record the task points at no longer exists, and the interface says so. There is
 * no foreign key behind a task's subject — `TaskSubjectType` explains why — so the alternative to checking
 * is a link that renders as a live one and goes nowhere.
 */
final readonly class TaskSubjectView
{
    public function __construct(
        public TaskSubjectType $type,
        public string $id,
        /** Null means the subject is gone, not that it is unnamed. */
        public ?string $label,
        public bool $exists,
    ) {}

    public function path(): string
    {
        return $this->type->path($this->id);
    }
}
