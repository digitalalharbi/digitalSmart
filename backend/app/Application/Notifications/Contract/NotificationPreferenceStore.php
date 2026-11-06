<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use App\Application\Notifications\DTO\NotificationPreferences;
use App\Domain\Notifications\NotificationCategory;
use DateTimeImmutable;

interface NotificationPreferenceStore
{
    /** The person's own preferences, or the defaults when they have never set any. */
    public function get(string $workspaceId, string $userId): NotificationPreferences;

    public function put(string $workspaceId, string $userId, NotificationPreferences $preferences): void;

    /** Whether this client is the workspace's own. A scope naming another workspace's client is refused, not saved. */
    public function clientExists(string $workspaceId, string $clientId): bool;

    /**
     * How many of these project ids are the workspace's own — compared to the count asked for by the caller.
     *
     * @param  list<string>  $projectIds
     */
    public function knownProjects(string $workspaceId, array $projectIds): int;

    /**
     * Of these people, the ones who muted this tab — asked once per announcement, not once per recipient.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function mutedAmong(string $workspaceId, NotificationCategory $category, array $userIds): array;

    /** Snooze until this instant, or clear the snooze with null. Kept apart from `put`, which never touches it. */
    public function snooze(string $workspaceId, string $userId, ?DateTimeImmutable $until): void;

    /**
     * Of these people, the ones who asked not to be interrupted at this instant — snoozed, or inside quiet hours in
     * their own zone or the workspace's.
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    public function quietAmong(string $workspaceId, array $userIds, DateTimeImmutable $now): array;
}
