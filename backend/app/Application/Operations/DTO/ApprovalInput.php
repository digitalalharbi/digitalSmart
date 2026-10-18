<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\ApprovalKind;
use App\Domain\Operations\TaskSubjectType;
use DateTimeImmutable;

/** What a caller supplies to ask for permission. */
final readonly class ApprovalInput
{
    /** @param array<string, mixed> $proposal */
    public function __construct(
        public ApprovalKind $kind,
        public string $title,
        /** Why. Required by the use case and by a CHECK: a change with no stated reason is unapprovable. */
        public string $rationale,
        public array $proposal,
        public ?string $clientId = null,
        public ?string $projectId = null,
        public ?TaskSubjectType $subjectType = null,
        public ?string $subjectId = null,
        /** The request this one replaces, where a declined proposal is being raised again changed. */
        public ?string $supersedesId = null,
        /** Who is proposing: a person, or one of the agents (named). */
        public string $origin = 'person',
        public ?string $agent = null,
        /**
         * What an agent's proposal rests on — the figures and the target compared against, as data. Never prose a
         * model wrote: the decider reads the numbers.
         *
         * @var array<string, mixed>|null
         */
        public ?array $evidence = null,
        public ?string $risk = null,
        /** After this the proposal is not worth deciding: the figures it rests on have moved. */
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    public function hasWholeSubject(): bool
    {
        return ($this->subjectType === null) === ($this->subjectId === null);
    }
}
