<?php

declare(strict_types=1);

namespace App\Application\Workspace\DTO;

/**
 * Open work in one person's name, by kind.
 *
 * Counted per kind rather than totalled, because the kinds are answered by different people: tasks and briefs
 * are an operations question, a client request is somebody waiting for a reply, and a lead is a person who has
 * not been rung back. A single number would say "seven things" and leave the reader to find out which.
 */
final readonly class HeldWork
{
    public function __construct(
        public int $tasks = 0,
        public int $leads = 0,
        public int $clientRequests = 0,
        public int $creativeRequests = 0,
    ) {}

    public function total(): int
    {
        return $this->tasks + $this->leads + $this->clientRequests + $this->creativeRequests;
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /** @return array{tasks: int, leads: int, client_requests: int, creative_requests: int, total: int} */
    public function toArray(): array
    {
        return [
            'tasks' => $this->tasks,
            'leads' => $this->leads,
            'client_requests' => $this->clientRequests,
            'creative_requests' => $this->creativeRequests,
            'total' => $this->total(),
        ];
    }
}
