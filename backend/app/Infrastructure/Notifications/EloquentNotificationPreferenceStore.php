<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\NotificationPreferenceStore;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationCategory;
use Illuminate\Support\Facades\DB;

final readonly class EloquentNotificationPreferenceStore implements NotificationPreferenceStore
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function get(string $workspaceId, string $userId): NotificationPreferences
    {
        $row = DB::table('notification_preferences')->where(['workspace_id' => $workspaceId, 'user_id' => $userId])->first();

        if ($row === null) {
            return NotificationPreferences::defaults();
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        return new NotificationPreferences(
            daily: (bool) $values['daily'],
            weekly: (bool) $values['weekly'],
            monthly: (bool) $values['monthly'],
            channels: array_values(array_filter(array_map(
                static fn (mixed $value): ?DigestChannel => is_string($value) ? DigestChannel::tryFrom($value) : null,
                $this->list($values['channels'] ?? null) ?? [],
            ))),
            timezone: is_string($values['timezone']) && $values['timezone'] !== '' ? $values['timezone'] : null,
            hour: (int) $values['hour'],
            clientId: is_string($values['client_id']) ? $values['client_id'] : null,
            projectIds: $this->list($values['project_ids'] ?? null),
            mutedCategories: array_values(array_filter(array_map(
                static fn (mixed $value): ?NotificationCategory => is_string($value) ? NotificationCategory::tryFrom($value) : null,
                $this->list($values['muted_categories'] ?? null) ?? [],
            ))),
        );
    }

    public function put(string $workspaceId, string $userId, NotificationPreferences $preferences): void
    {
        $now = now();

        DB::table('notification_preferences')->upsert(
            [[
                'id' => $this->identifiers->generate(),
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'daily' => $preferences->daily,
                'weekly' => $preferences->weekly,
                'monthly' => $preferences->monthly,
                'channels' => json_encode(array_map(static fn (DigestChannel $c): string => $c->value, $preferences->channels), JSON_THROW_ON_ERROR),
                'timezone' => $preferences->timezone,
                'hour' => $preferences->hour,
                'client_id' => $preferences->clientId,
                'project_ids' => $preferences->projectIds === null ? null : json_encode($preferences->projectIds, JSON_THROW_ON_ERROR),
                'muted_categories' => json_encode(array_map(static fn (NotificationCategory $c): string => $c->value, $preferences->mutedCategories), JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['workspace_id', 'user_id'],
            ['daily', 'weekly', 'monthly', 'channels', 'timezone', 'hour', 'client_id', 'project_ids', 'muted_categories', 'updated_at'],
        );
    }

    public function clientExists(string $workspaceId, string $clientId): bool
    {
        return DB::table('clients')->where(['workspace_id' => $workspaceId, 'id' => $clientId])->exists();
    }

    public function knownProjects(string $workspaceId, array $projectIds): int
    {
        if ($projectIds === []) {
            return 0;
        }

        return DB::table('projects')->where('workspace_id', $workspaceId)->whereIn('id', $projectIds)->count();
    }

    public function mutedAmong(string $workspaceId, NotificationCategory $category, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            DB::table('notification_preferences')
                ->where('workspace_id', $workspaceId)
                ->whereIn('user_id', $userIds)
                ->whereRaw('muted_categories @> ?', [json_encode([$category->value], JSON_THROW_ON_ERROR)])
                ->pluck('user_id')
                ->all(),
        ));
    }

    /** @return list<string>|null */
    private function list(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return null;
        }

        return array_values(array_filter(array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, $decoded)));
    }
}
