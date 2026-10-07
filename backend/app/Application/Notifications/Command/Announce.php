<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationBroadcaster;
use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Notifications\Contract\RecipientResolver;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Notifications\DTO\NotificationView;
use App\Application\Shared\Contract\ActorContext;
use Throwable;

/**
 * Tell the people who need to know.
 *
 * The one entry point, and it enforces three rules that would otherwise be re-decided at every call site.
 *
 * **Never tell somebody what they just did.** The actor is dropped before anything is written. A notification
 * about your own action is noise, and noise is what teaches people to stop reading the bell — after which the
 * one notification that mattered arrives in a stream nobody opens.
 *
 * **Never tell somebody something they could not have read.** The resolver checks permission and project
 * visibility. A notification is a disclosure, and this is the only place that fact is enforced.
 *
 * **Announcing must never fail the action it describes.** Same contract the audit recorder has, for the same
 * reason: an approval that was recorded but whose notification threw would leave a decision made and a
 * requester never told, and the caller would see a failure for something that did happen.
 */
final readonly class Announce
{
    public function __construct(
        private NotificationStore $store,
        private RecipientResolver $recipients,
        private NotificationBroadcaster $broadcaster,
        private ActorContext $actor,
    ) {}

    /** @return list<NotificationView> what was written, which may legitimately be nothing */
    public function __invoke(NotificationDraft $draft): array
    {
        try {
            $workspaceId = $this->actor->workspaceId()->value;
            $actorId = $this->actor->userId()->value;

            $userIds = array_values(array_filter(
                $this->recipients->resolve($workspaceId, $draft),
                // The actor, dropped. See the note above.
                static fn (string $userId): bool => $userId !== $actorId,
            ));

            if ($userIds === []) {
                /*
                 * A legitimate outcome, not an error. A one-person workspace assigning themselves a task has
                 * nobody to tell, and a workspace where nobody can decide a spend change has a permissions
                 * problem rather than a notification problem.
                 */
                return [];
            }

            $written = $this->store->announce(
                $workspaceId,
                $userIds,
                $draft->kind,
                $draft->title,
                $draft->body,
                $draft->kind->subject(),
                $draft->subjectId,
                $actorId,
            );

            /*
             * Broadcast from what was stored, never from the draft. A notification pushed to a browser that is
             * not in the database is one a reload makes vanish — worse than no realtime at all, because the
             * person has already acted on having seen it.
             */
            $this->broadcaster->push($workspaceId, $written);

            return $written;
        } catch (Throwable $failure) {
            report($failure);

            return [];
        }
    }
}
