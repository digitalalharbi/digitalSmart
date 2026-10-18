<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Domain\Operations\ApprovalKind;

/**
 * How a caller narrows the approval queue.
 *
 * One object for the same reason the task filter is one: the two queues and the figures beside them must be
 * narrowed identically, and a filter threaded by hand through three reads is a filter that eventually applies
 * to two of them.
 */
final readonly class ApprovalFilter
{
    public function __construct(
        public ?string $clientId = null,
        public ?ApprovalKind $kind = null,
        /** True to show only the requests that would move a client's money. */
        public bool $spendOnly = false,
        public ?string $projectId = null,
    ) {}

    public static function everything(): self
    {
        return new self;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'kind' => $this->kind?->value,
            'spend_only' => $this->spendOnly,
            'project_id' => $this->projectId,
        ];
    }
}
