<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Notifications\DTO\NotificationView;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Notifications\NotificationDigest;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Notifications\NotificationSubject;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Notifications, one row per recipient.
 *
 * Two decisions worth reading.
 *
 * **Fan-out happens at write time, in one insert.** A row per recipient is more rows than a row per event, and
 * it is the only shape that can record that one of five people has read something. The alternative — one row
 * and a join table of reads — is the same number of rows with an extra join on the hottest read in the module.
 *
 * **The counts are aggregated in SQL, in one query.** An unread badge computed from whatever is in a browser's
 * cache disagrees with the list below it, and a person who has seen that disagreement stops believing the badge.
 */
final readonly class EloquentNotificationStore implements NotificationStore
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function announce(string $workspaceId, array $userIds, NotificationDraft $draft, ?string $actorId): array
    {
        if ($userIds === []) {
            return [];
        }

        $now = now();
        $rows = [];
        $ids = [];

        foreach (array_unique($userIds) as $userId) {
            $id = $this->identifiers->generate();
            $ids[] = $id;

            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'actor_id' => $actorId,
                'kind' => $draft->kind->value,
                'category' => $draft->kind->category()->value,
                'severity' => $draft->severity->value,
                'title' => $draft->title,
                'body' => $draft->body,
                'payload' => $draft->payload === null ? null : json_encode($draft->payload, JSON_THROW_ON_ERROR),
                'dedupe_key' => $draft->dedupeKey,
                'subject_type' => $draft->kind->subject()->value,
                'subject_id' => $draft->subjectId,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // One insert for the whole fan-out: five recipients should not be five round trips.
        DB::table('notifications')->insert($rows);

        return $this->byIds($workspaceId, $ids);
    }

    public function lastSeverity(string $workspaceId, string $dedupeKey, DateTimeImmutable $since): ?NotificationSeverity
    {
        $worst = null;

        foreach (DB::table('notifications')
            ->where('workspace_id', $workspaceId)
            ->where('dedupe_key', $dedupeKey)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('severity') as $value) {
            $severity = NotificationSeverity::tryFrom((string) $value);

            if ($severity !== null && ($worst === null || $severity->rank() > $worst->rank())) {
                $worst = $severity;
            }
        }

        return $worst;
    }

    public function forUser(string $workspaceId, string $userId, bool $unreadOnly, int $limit): array
    {
        $builder = DB::table('notifications as n')
            ->leftJoin('users as a', 'a.id', '=', 'n.actor_id')
            ->where('n.workspace_id', $workspaceId)
            ->where('n.user_id', $userId);

        if ($unreadOnly) {
            $builder->whereNull('n.read_at');
        }

        $rows = $builder
            ->orderByDesc('n.created_at')
            ->limit($limit)
            ->get(['n.*', 'a.name as actor_name']);

        return $this->views($this->rows($rows->all()));
    }

    public function digest(string $workspaceId, string $userId): NotificationDigest
    {
        /*
         * One grouped query for every figure. Counting in PHP over the capped list would make the badge agree
         * with the page rather than with the database — which is the same thing as being wrong.
         */
        $rows = DB::table('notifications')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->groupBy('kind', 'read_at')
            ->get(['kind', DB::raw('(read_at is null) as is_unread'), DB::raw('count(*) as tally')]);

        $unreadByKind = array_fill_keys(NotificationKind::values(), 0);
        $total = $unread = $waiting = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $tally = (int) ($values['tally'] ?? 0);
            $kind = NotificationKind::tryFrom((string) ($values['kind'] ?? ''));
            $isUnread = (bool) ($values['is_unread'] ?? false);

            $total += $tally;

            if (! $isUnread || $kind === null) {
                continue;
            }

            $unread += $tally;
            $unreadByKind[$kind->value] += $tally;

            if ($kind->needsAction()) {
                $waiting += $tally;
            }
        }

        return new NotificationDigest(
            total: $total,
            unread: $unread,
            waiting: $waiting,
            unreadByKind: $unreadByKind,
        );
    }

    public function markRead(string $workspaceId, string $userId, string $notificationId): bool
    {
        /*
         * Both statements are scoped by workspace *and* user, so there is no path here where a guessed id marks
         * somebody else's notification read. Two statements rather than one `coalesce`, because the two
         * questions are different: whether this notification is yours, and whether it was already read. Marking
         * something read twice is not an error and must not change the time it was first read.
         */
        $mine = DB::table('notifications')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('id', $notificationId);

        if (! $mine->exists()) {
            return false;
        }

        DB::table('notifications')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('id', $notificationId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return true;
    }

    public function markAllRead(string $workspaceId, string $userId): int
    {
        return DB::table('notifications')
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  list<string>  $ids
     * @return list<NotificationView>
     */
    private function byIds(string $workspaceId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('notifications as n')
            ->leftJoin('users as a', 'a.id', '=', 'n.actor_id')
            ->where('n.workspace_id', $workspaceId)
            ->whereIn('n.id', $ids)
            ->orderBy('n.created_at')
            ->get(['n.*', 'a.name as actor_name']);

        return $this->views($this->rows($rows->all()));
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        /** @var list<array<string, mixed>> $values */
        $values = [];

        foreach ($rows as $row) {
            $values[] = (array) $row;
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<NotificationView>
     */
    private function views(array $rows): array
    {
        $labels = $this->resolveSubjects($rows);
        $views = [];

        foreach ($rows as $values) {
            $subject = NotificationSubject::tryFrom((string) ($values['subject_type'] ?? ''))
                ?? NotificationSubject::Task;
            $subjectId = (string) ($values['subject_id'] ?? '');
            $resolved = $labels[$subject->value] ?? [];

            $views[] = new NotificationView(
                id: (string) ($values['id'] ?? ''),
                recipientId: (string) ($values['user_id'] ?? ''),
                kind: NotificationKind::tryFrom((string) ($values['kind'] ?? ''))
                    ?? NotificationKind::TaskAssigned,
                title: (string) ($values['title'] ?? ''),
                body: $this->text($values['body'] ?? null),
                subject: $subject,
                subjectId: $subjectId,
                subjectLabel: $resolved[$subjectId] ?? null,
                // Present in the lookup at all means the row is still there.
                subjectExists: array_key_exists($subjectId, $resolved),
                actorName: $this->text($values['actor_name'] ?? null),
                readAt: $this->date($values['read_at'] ?? null),
                createdAt: $this->date($values['created_at'] ?? null) ?? new DateTimeImmutable('@0'),
                severity: NotificationSeverity::tryFrom((string) ($values['severity'] ?? '')) ?? NotificationSeverity::Info,
                payload: $this->payload($values['payload'] ?? null),
                clickedAt: $this->date($values['clicked_at'] ?? null),
                dismissedAt: $this->date($values['dismissed_at'] ?? null),
            );
        }

        return $views;
    }

    /**
     * Subject titles, one query per subject type present.
     *
     * A notification whose subject has been deleted comes back as `exists: false`. There is no foreign key —
     * the same trade `TaskSubjectType` explains — so the alternative is a notification that opens nothing.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, ?string>>
     */
    private function resolveSubjects(array $rows): array
    {
        /** @var array<string, list<string>> $wanted */
        $wanted = [];

        foreach ($rows as $values) {
            $subject = NotificationSubject::tryFrom((string) ($values['subject_type'] ?? ''));
            $id = $this->text($values['subject_id'] ?? null);

            if ($subject !== null && $id !== null) {
                $wanted[$subject->value][] = $id;
            }
        }

        $resolved = [];

        foreach ($wanted as $subjectValue => $ids) {
            $subject = NotificationSubject::from($subjectValue);
            $labels = [];

            $found = DB::table($subject->table())
                ->whereIn('id', array_values(array_unique($ids)))
                ->get(['id', $subject->labelColumn().' as label']);

            foreach ($found as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;
                $labels[(string) ($values['id'] ?? '')] = $this->text($values['label'] ?? null);
            }

            $resolved[$subjectValue] = $labels;
        }

        return $resolved;
    }

    /** @return array<string, mixed>|null */
    private function payload(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}
