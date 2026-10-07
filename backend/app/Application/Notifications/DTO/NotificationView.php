<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSubject;
use DateTimeImmutable;

/** One notification, as its recipient reads it. */
final readonly class NotificationView
{
    public function __construct(
        public string $id,
        /**
         * Who this row belongs to.
         *
         * Carried for one reason: the realtime channel is per person, so a push has to know where to send it.
         * It is never rendered — a reader is always the recipient — and the HTTP resource leaves it out, because
         * a field in a socket payload is the one place an unconsidered addition reaches a browser whose
         * permissions nobody re-checked.
         */
        public string $recipientId,
        public NotificationKind $kind,
        public string $title,
        public ?string $body,
        public NotificationSubject $subject,
        public string $subjectId,
        /** Resolved on every read. Null means the thing is gone, not that it is unnamed. */
        public ?string $subjectLabel,
        public bool $subjectExists,
        public ?string $actorName,
        public ?DateTimeImmutable $readAt,
        public DateTimeImmutable $createdAt,
    ) {}

    public function isUnread(): bool
    {
        return $this->readAt === null;
    }

    /** Unread and of a kind its recipient is expected to act on. The number that belongs on a bell. */
    public function isWaiting(): bool
    {
        return $this->isUnread() && $this->kind->needsAction();
    }

    public function path(): string
    {
        return $this->subject->path();
    }
}
