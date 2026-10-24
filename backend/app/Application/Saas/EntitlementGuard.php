<?php

declare(strict_types=1);

namespace App\Application\Saas;

use App\Application\Saas\Contract\CapacityLock;
use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Saas\Contract\UsageReader;
use App\Application\Saas\DTO\Entitlements;
use App\Application\Saas\Exception\FeatureNotEntitled;
use App\Application\Saas\Exception\QuotaExceeded;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The commercial half of "may this happen?": does the workspace have the feature, and is there room under the limit.
 *
 * It sits beside the permission guard, never instead of it. A use case asks both; neither answers for the other. Defaults to the
 * actor's workspace; a caller with no actor (a public form, a scheduled job) names the workspace.
 */
final readonly class EntitlementGuard
{
    public function __construct(
        private EntitlementStore $store,
        private UsageReader $usage,
        private CapacityLock $lock,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    public function entitlements(?string $workspaceId = null): Entitlements
    {
        return $this->store->forWorkspace($workspaceId ?? $this->actor->workspaceId()->value);
    }

    public function ensureFeature(Feature $feature, ?string $workspaceId = null): void
    {
        $entitlements = $this->entitlements($workspaceId);

        if (! $entitlements->has($feature)) {
            throw new FeatureNotEntitled($feature, $entitlements->planCode);
        }
    }

    /** Refuse when adding `$adding` more would pass the limit. A limit that does not refuse (visitor events) never throws. */
    public function ensureCapacity(Limit $limit, int $adding = 1, ?string $workspaceId = null): void
    {
        $workspace = $workspaceId ?? $this->actor->workspaceId()->value;
        $allowed = $this->store->forWorkspace($workspace)->limit($limit);

        if ($allowed === null || ! $limit->refusesWhenExhausted()) {
            return;
        }

        $now = $this->clock->now();
        $used = $this->usage->used($workspace, $limit, $now) ?? 0;

        if ($used + $adding > $allowed) {
            throw new QuotaExceeded($limit, $used, $allowed, $limit->isMonthly() ? self::nextMonth($now) : null);
        }
    }

    /**
     * Check the limit and do the work that uses it, under one lock, so the last unit cannot be claimed twice.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function claim(Limit $limit, callable $work, ?string $workspaceId = null): mixed
    {
        $workspace = $workspaceId ?? $this->actor->workspaceId()->value;

        return $this->lock->within($workspace, $limit, function () use ($limit, $workspace, $work): mixed {
            $this->ensureCapacity($limit, 1, $workspace);

            return $work();
        });
    }

    /** Count a monthly event once, after checking there is room. */
    public function meter(Limit $limit, string $eventKey, ?string $workspaceId = null): void
    {
        $workspace = $workspaceId ?? $this->actor->workspaceId()->value;
        $this->ensureCapacity($limit, 1, $workspace);
        $this->usage->record($workspace, $limit, $eventKey, $this->clock->now());
    }

    public static function nextMonth(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of next month')->setTime(0, 0);
    }
}
