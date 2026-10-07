<?php

declare(strict_types=1);

namespace App\Application\Creative\DTO;

use App\Domain\Creative\CreativeState;
use DateTimeImmutable;

/**
 * The board, as columns.
 *
 * Every state is a column even when it is empty, because a board whose columns appear and disappear with
 * the work is a board nobody can learn the shape of.
 */
final readonly class CreativeBoardView
{
    /** @param array<string, list<CreativeRequestView>> $columns keyed by state value */
    public function __construct(
        public array $columns,
        public DateTimeImmutable $now,
    ) {}

    /** @return list<CreativeRequestView> */
    public function column(CreativeState $state): array
    {
        return $this->columns[$state->value] ?? [];
    }

    public function openCount(): int
    {
        $open = 0;

        foreach (CreativeState::cases() as $state) {
            if ($state->isOpen()) {
                $open += count($this->column($state));
            }
        }

        return $open;
    }

    /** Work sitting with a reviewer, which is the number a producer wants on the page. */
    public function waitingOnReview(): int
    {
        return count($this->column(CreativeState::InReview));
    }

    public function overdueCount(): int
    {
        $overdue = 0;

        foreach ($this->columns as $requests) {
            foreach ($requests as $request) {
                if ($request->isOverdue($this->now)) {
                    $overdue += 1;
                }
            }
        }

        return $overdue;
    }
}
