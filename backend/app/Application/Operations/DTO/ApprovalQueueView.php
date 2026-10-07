<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\ApprovalAge;
use App\Domain\Operations\ApprovalLoad;
use DateTimeImmutable;

/**
 * The approval queue: what is waiting for a decision, what is waiting to be done, and the rest.
 *
 * **Two queues, not one.** Requests waiting for a decision and approved changes waiting for a hand are
 * different backlogs with different owners, and merging them would hide the second — which is the one that
 * ends with a client being told something untrue.
 */
final readonly class ApprovalQueueView
{
    /**
     * @param  list<ApprovalView>  $pending  oldest first: the thing that has waited longest is the thing to read
     * @param  list<ApprovalView>  $outstanding  approved, not yet carried out, oldest first
     * @param  list<ApprovalView>  $settled  recently decided, newest first
     */
    public function __construct(
        public array $pending,
        public array $outstanding,
        public array $settled,
        public ApprovalLoad $load,
        public DateTimeImmutable $now,
    ) {}

    /** @return array<string, int> */
    public function pendingByAge(): array
    {
        return $this->load->pendingByAge;
    }

    public function inAge(ApprovalAge $age): int
    {
        return $this->load->outstandingByAge[$age->value] ?? 0;
    }
}
