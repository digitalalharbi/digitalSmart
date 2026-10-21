<?php

declare(strict_types=1);

namespace App\Application\Digest\Command;

use App\Application\Digest\Contract\DigestStore;
use App\Application\Digest\DTO\PerformanceDigestPeriod;
use App\Application\Digest\Service\PerformanceDigestComposer;
use App\Application\Notifications\Contract\NotificationStore;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\DigestKind;
use App\Domain\Identity\UserId;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Workspace\WorkspaceId;
use DateTimeZone;
use Exception;
use Throwable;

/**
 * The daily, weekly and monthly performance digests, each landing at nine in the workspace's own morning.
 *
 * `dispatch()` is what the scheduler calls every hour: for each workspace it reads the local time and builds
 * whichever digests are due — daily once the local hour has reached nine, weekly on the local Sunday, monthly on
 * the local first — and skips a workspace where that period's digest already exists. So a workspace in Riyadh and
 * one in Jakarta each get theirs at their own nine, and a scheduler that ran late still delivers rather than
 * missing the minute.
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
    ) {}

    /** @return array<string, array{written: int, skipped: int, failed: int}> keyed by digest kind */
    public function dispatch(?string $workspaceId = null): array
    {
        $now = $this->clock->now();
        $results = [];

        foreach ($this->store->workspaces($workspaceId) as $workspace) {
            $zone = $this->zone($workspace['timezone']);
            $local = $now->setTimezone($zone);

            if ((int) $local->format('G') < self::LOCAL_HOUR) {
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
                $period = PerformanceDigestPeriod::for($kind, $now, $zone);

                if ($this->store->exists($workspace['workspace_id'], $kind, $period->on)) {
                    continue;
                }

                $result = $this->build($kind, $workspace['workspace_id'], $zone);
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
        $written = $skipped = $failed = 0;
        $now = $this->clock->now();
        $zones = [];

        foreach ($this->store->workspaces($workspaceId) as $workspace) {
            $zones[$workspace['workspace_id']] = $zone ?? $this->zone($workspace['timezone']);
        }

        foreach ($this->store->recipients($workspaceId) as $recipient) {
            $workspace = $recipient['workspace_id'];

            try {
                $this->actors->bind(new UserId($recipient['user_id']), new WorkspaceId($workspace));
                $period = PerformanceDigestPeriod::for($kind, $now, $zones[$workspace] ?? new DateTimeZone('UTC'));
                $digest = $this->composer->compose($workspace, $period);

                if ($digest === null) {
                    $skipped++;

                    continue;
                }

                $digestId = $this->store->put($workspace, $recipient['user_id'], $kind, $period->on, $digest['needs_attention'], $digest);

                if ($digestId !== null) {
                    $this->notifications->announce($workspace, [$recipient['user_id']], $this->draft($kind, $digestId, $digest), null);
                }

                $written++;
            } catch (Throwable $failure) {
                report($failure);
                $failed++;
            } finally {
                $this->actors->clear();
            }
        }

        return ['written' => $written, 'skipped' => $skipped, 'failed' => $failed];
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
