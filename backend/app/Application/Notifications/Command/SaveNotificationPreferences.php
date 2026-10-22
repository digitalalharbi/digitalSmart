<?php

declare(strict_types=1);

namespace App\Application\Notifications\Command;

use App\Application\Notifications\Contract\NotificationPreferenceStore;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Notifications\Exception\InvalidNotificationPreference;
use DateTimeZone;

/**
 * Save your own preferences.
 *
 * A client or project scope has to be one the person could see anyway: a preference cannot widen what a digest
 * shows, only narrow it, and the composer re-checks visibility when it reads. A time zone must be one PHP knows,
 * because a digest scheduled in a zone that does not exist is a digest that never comes.
 */
final readonly class SaveNotificationPreferences
{
    public function __construct(private NotificationPreferenceStore $store, private ActorContext $actor) {}

    public function __invoke(NotificationPreferences $preferences): NotificationPreferences
    {
        $workspaceId = $this->actor->workspaceId()->value;

        if ($preferences->timezone !== null && ! in_array($preferences->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw InvalidNotificationPreference::unknownTimezone($preferences->timezone);
        }

        if ($preferences->clientId !== null && ! $this->store->clientExists($workspaceId, $preferences->clientId)) {
            throw ResourceNotFound::of('Client');
        }

        if ($preferences->projectIds !== null) {
            $unique = array_values(array_unique($preferences->projectIds));

            if ($this->store->knownProjects($workspaceId, $unique) !== count($unique)) {
                throw ResourceNotFound::of('Project');
            }
        }

        $this->store->put($workspaceId, $this->actor->userId()->value, $preferences);

        return $preferences;
    }
}
