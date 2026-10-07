<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

/**
 * One person's open load, broken down by priority.
 *
 * `userId` is null for the unassigned pile, which is a row like any other. Leaving it out would be the
 * flattering version: a chart of named people only shows a comfortable team beside a stack of work nobody
 * has agreed to do.
 */
final readonly class AssigneeLoadView
{
    /** @param array<string, int> $byPriority counts keyed by `TaskPriority` value */
    public function __construct(
        public ?string $userId,
        public ?string $name,
        public int $open,
        public int $overdue,
        public array $byPriority,
    ) {}

    public function isUnassigned(): bool
    {
        return $this->userId === null;
    }
}
