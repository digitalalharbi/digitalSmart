<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskSubjectType;

/**
 * How a caller narrows the board.
 *
 * One object rather than six parameters, because every read on this module — the cards, the counts and the
 * per-person load — has to be narrowed **identically**. A filter threaded by hand through three methods is
 * a filter that eventually applies to two of them, and then the chart beside the board disagrees with it.
 */
final readonly class TaskFilter
{
    public function __construct(
        public ?string $clientId = null,
        public ?string $projectId = null,
        /** A specific person, or `MINE` resolved by the query to the actor. */
        public ?string $assigneeId = null,
        /** True to ask for the unassigned pile only. Distinct from `assigneeId` being null, which is "anyone". */
        public bool $unassignedOnly = false,
        public ?TaskPriority $priority = null,
        public ?TaskSubjectType $subjectType = null,
        public ?string $subjectId = null,
    ) {}

    public static function everything(): self
    {
        return new self;
    }

    public function forClient(?string $clientId): self
    {
        return new self(
            $clientId,
            $this->projectId,
            $this->assigneeId,
            $this->unassignedOnly,
            $this->priority,
            $this->subjectType,
            $this->subjectId,
        );
    }

    public function forAssignee(?string $assigneeId): self
    {
        return new self(
            $this->clientId,
            $this->projectId,
            $assigneeId,
            $this->unassignedOnly,
            $this->priority,
            $this->subjectType,
            $this->subjectId,
        );
    }

    /** @return array<string, mixed> the filter as the audit log and the cache key see it */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'project_id' => $this->projectId,
            'assignee_id' => $this->assigneeId,
            'unassigned' => $this->unassignedOnly,
            'priority' => $this->priority?->value,
            'subject_type' => $this->subjectType?->value,
            'subject_id' => $this->subjectId,
        ];
    }
}
