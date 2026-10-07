<?php

declare(strict_types=1);

namespace App\Application\Operations\DTO;

use App\Domain\Operations\ClientRequestKind;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\StatedUrgency;
use DateTimeImmutable;

/** One client request, with the conversation on it. */
final readonly class ClientRequestView
{
    /**
     * @param  list<ClientRequestMessageView>  $messages  already filtered to what the reader may see
     * @param  list<TaskView>  $tasks
     */
    public function __construct(
        public string $id,
        public string $clientId,
        public string $clientName,
        public ClientRequestKind $kind,
        public string $title,
        public ?string $detail,
        public ClientRequestState $state,
        /** The client's word. Never this product's priority — see `StatedUrgency`. */
        public StatedUrgency $urgency,
        public ?string $projectId,
        public ?string $projectName,
        public ?DateTimeImmutable $wantedBy,
        /** What the agency agreed to. Null means nothing has been promised, which is a fact worth publishing. */
        public ?DateTimeImmutable $promisedAt,
        public ?string $promisedByName,
        public ?string $submittedByName,
        /*
         * The id as well as the name. A name is for a reader; an id is who an audit entry records and who a
         * notification goes to, and resolving one from the other fails the day two people share a name.
         */
        public ?string $ownerId,
        public ?string $ownerName,
        public ?string $declinedReason,
        public ?string $neededInformation,
        public ?DateTimeImmutable $firstAnsweredAt,
        public ?DateTimeImmutable $deliveredAt,
        public ?DateTimeImmutable $stateChangedAt,
        public DateTimeImmutable $createdAt,
        public array $messages,
        /**
         * Tasks raised from this request. The link that keeps an intake queue from being a second board.
         *
         * @var list<TaskView>
         */
        public array $tasks,
    ) {}

    /**
     * The same request with a different set of linked tasks.
     *
     * Exists so raising a task does not need a second full read of the request: the row did not change, only the
     * work attached to it did. Returns a new instance rather than mutating, like every other DTO here.
     *
     * @param  list<TaskView>  $tasks
     */
    public function withTasks(array $tasks): self
    {
        return new self(
            $this->id,
            $this->clientId,
            $this->clientName,
            $this->kind,
            $this->title,
            $this->detail,
            $this->state,
            $this->urgency,
            $this->projectId,
            $this->projectName,
            $this->wantedBy,
            $this->promisedAt,
            $this->promisedByName,
            $this->submittedByName,
            $this->ownerId,
            $this->ownerName,
            $this->declinedReason,
            $this->neededInformation,
            $this->firstAnsweredAt,
            $this->deliveredAt,
            $this->stateChangedAt,
            $this->createdAt,
            $this->messages,
            $tasks,
        );
    }

    /**
     * Whether the promised date has passed with the work still open.
     *
     * False with no promise. An unpromised request cannot be late — it can only be undated, which is a different
     * problem with a different number beside it.
     */
    public function isOverdue(DateTimeImmutable $now): bool
    {
        if ($this->promisedAt === null || ! $this->state->isOpen()) {
            return false;
        }

        return (int) $this->promisedAt->format('Ymd') < (int) $now->format('Ymd');
    }

    /**
     * How long the client waited for any answer at all, in whole days.
     *
     * Measured to the first answer where there is one, and to **now** where there is not — because an
     * unanswered request's wait is still running, and reporting null would hide exactly the row that matters.
     */
    public function waitingForAnswerDays(DateTimeImmutable $now): int
    {
        $until = $this->firstAnsweredAt ?? $now;

        return (int) $this->createdAt->setTime(0, 0)->diff($until->setTime(0, 0))->days;
    }

    /** Whether anybody has said anything to the client yet. */
    public function isUnanswered(): bool
    {
        return $this->firstAnsweredAt === null;
    }

    /**
     * How long it took from arrival to delivery, in whole days. Null while undelivered.
     *
     * From arrival rather than from acceptance, deliberately: the client's experience of how long something took
     * starts when they asked, not when the agency got round to agreeing.
     */
    public function turnaroundDays(): ?int
    {
        return $this->deliveredAt === null
            ? null
            : (int) $this->createdAt->setTime(0, 0)->diff($this->deliveredAt->setTime(0, 0))->days;
    }
}
