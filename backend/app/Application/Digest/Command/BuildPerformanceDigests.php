<?php

declare(strict_types=1);

namespace App\Application\Digest\Command;

use App\Application\Digest\Contract\DigestStore;
use App\Application\Digest\DTO\PerformanceDigestPeriod;
use App\Application\Digest\Service\PerformanceDigestComposer;
use App\Application\Notifications\Contract\NotificationPreferenceStore;
use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Notifications\DTO\NotificationPreferences;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\DigestKind;
use App\Domain\Identity\UserId;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Workspace\WorkspaceId;
use DateTimeZone;
use Exception;
use Throwable;

/**
 * The daily, weekly and monthly performance digests, each landing at nine in the workspace's own morning.
 *
 * `dispatch()` is what the scheduler calls every hour: for each person it reads the local time — their own zone,
 * or the workspace's — and builds whichever digests they want that are due: daily once the local hour has reached
 * the one they chose (nine by default), weekly on the local Sunday, monthly on the local first — skipping a period
 * already written. So a workspace in Riyadh and one in Jakarta each get theirs at their own nine, somebody who
 * asked for seven gets it at seven, and a scheduler that ran late still delivers rather than missing the minute.
 *
 * `build()` writes one digest per person **as that person**: the actor is bound to them and the composer reads in
 * their scope, so a digest can never be a side door around visibility. Somebody with nothing to be told gets
 * nothing — a morning message saying nothing happened is the noise this exists to remove.
 */
final readonly class BuildPerformanceDigests
{
    public const int LOCAL_HOUR = 9;

    public function __construct(
        private DigestStore $store,
        private NotificationStore $notifications,
        private PerformanceDigestComposer $composer,
        private ActorContextBinder $actors,
        private Clock $clock,
        private NotificationPreferenceStore $preferences,
    ) {}

    /** @return array<string, array{written: int, skipped: int, failed: int}> keyed by digest kind */
    public function dispatch(?string $workspaceId = null): array
    {
        $now = $this->clock->now();
        $results = [];
        $zones = [];

        foreach ($this->store->workspaces($workspaceId) as $workspace) {
            $zones[$workspace['workspace_id']] = $workspace['timezone'];
        }

        /*
         * Per person, not per workspace: each one has a zone (theirs, or the workspace's), an hour, and a say in
         * which digests they want. What is due for one may not be due for the next.
         */
        foreach ($this->store->recipients($workspaceId) as $recipient) {
            $workspace = $recipient['workspace_id'];
            $preferences = $this->preferences->get($workspace, $recipient['user_id']);
            $zone = $this->zone($preferences->timezone ?? $zones[$workspace] ?? 'UTC');
            $local = $now->setTimezone($zone);

            if ((int) $local->format('G') < $preferences->hour) {
                continue;
            }

            $due = [DigestKind::DailyPerformance];

            if ((int) $local->format('w') === 0) {
                $due[] = DigestKind::WeeklyPerformance;
            }

            if ((int) $local->format('j') === 1) {
                $due[] = DigestKind::MonthlyPerformance;
            }

            foreach ($due as $kind) {
                if (! $preferences->wants($kind) || $preferences->mutes(NotificationCategory::Performance)) {
                    continue;
                }

                $period = PerformanceDigestPeriod::for($kind, $now, $zone);

                if ($this->store->existsFor($workspace, $recipient['user_id'], $kind, $period->on)) {
                    continue;
                }

                $result = $this->one($recipient['user_id'], $workspace, $kind, $period, $preferences);
                $results[$kind->value] = [
                    'written' => ($results[$kind->value]['written'] ?? 0) + $result['written'],
                    'skipped' => ($results[$kind->value]['skipped'] ?? 0) + $result['skipped'],
                    'failed' => ($results[$kind->value]['failed'] ?? 0) + $result['failed'],
                ];
            }
        }

        return $results;
    }

    /** @return array{written: int, skipped: int, failed: int} */
    public function build(DigestKind $kind, ?string $workspaceId = null, ?DateTimeZone $zone = null): array
    {
        $totals = ['written' => 0, 'skipped' => 0, 'failed' => 0];
        $now = $this->clock->now();
        $zones = [];

        foreach ($this->store->workspaces($workspaceId) as $workspace) {
            $zones[$workspace['workspace_id']] = $workspace['timezone'];
        }

        foreach ($this->store->recipients($workspaceId) as $recipient) {
            $workspace = $recipient['workspace_id'];
            $preferences = $this->preferences->get($workspace, $recipient['user_id']);
            $period = PerformanceDigestPeriod::for($kind, $now, $zone ?? $this->zone($preferences->timezone ?? $zones[$workspace] ?? 'UTC'));
            $result = $this->one($recipient['user_id'], $workspace, $kind, $period, $preferences);

            foreach ($result as $key => $count) {
                $totals[$key] += $count;
            }
        }

        return $totals;
    }

    /**
     * One person's digest, written as that person.
     *
     * @return array{written: int, skipped: int, failed: int}
     */
    private function one(string $userId, string $workspace, DigestKind $kind, PerformanceDigestPeriod $period, NotificationPreferences $preferences): array
    {
        try {
            $this->actors->bind(new UserId($userId), new WorkspaceId($workspace));
            $digest = $this->composer->compose($workspace, $period, $preferences);

            if ($digest === null) {
                return ['written' => 0, 'skipped' => 1, 'failed' => 0];
            }

            $digestId = $this->store->put($workspace, $userId, $kind, $period->on, $digest['needs_attention'], $digest);

            if ($digestId !== null) {
                $this->notifications->announce($workspace, [$userId], $this->draft($kind, $digestId, $digest), null);
            }

            return ['written' => 1, 'skipped' => 0, 'failed' => 0];
        } catch (Throwable $failure) {
            report($failure);

            return ['written' => 0, 'skipped' => 0, 'failed' => 1];
        } finally {
            $this->actors->clear();
        }
    }

    /** @param array<string, mixed> $digest */
    private function draft(DigestKind $kind, string $digestId, array $digest): NotificationDraft
    {
        $spend = $digest['figures']['spend'] ?? null;
        $needs = (int) $digest['needs_attention'];

        return new NotificationDraft(
            kind: NotificationKind::PerformanceDigest,
            title: $kind->label().($needs > 0 ? sprintf(' · %d %s attention', $needs, $needs === 1 ? 'thing needs' : 'things need') : ''),
            subjectId: $digestId,
            severity: NotificationSeverity::from((string) $digest['severity']),
            /* The headline a card draws before the digest is opened: spend and its change, the best platform, the count. */
            payload: [
                'cadence' => $kind->value,
                'period' => $digest['period'],
                'spend' => $spend,
                'roas' => $digest['figures']['roas'] ?? null,
                'cpa' => $digest['figures']['cpa'] ?? null,
                'results' => $digest['figures']['conversions'] ?? null,
                'best_platform' => $digest['best_platform']['label'] ?? null,
                'weakest_platform' => $digest['weakest_platform']['label'] ?? null,
                'needs_attention' => $needs,
            ],
        );
    }

    private function zone(string $name): DateTimeZone
    {
        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
