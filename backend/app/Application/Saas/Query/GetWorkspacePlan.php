<?php

declare(strict_types=1);

namespace App\Application\Saas\Query;

use App\Application\Saas\Contract\EntitlementStore;
use App\Application\Saas\Contract\UsageReader;
use App\Application\Saas\DTO\WorkspacePlanView;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;

/**
 * The plan and its usage, for the people who administer the workspace.
 *
 * `used` is null where the thing cannot be measured yet — campaign launches do not exist until the launch PRs land — and the page
 * says so rather than showing a zero. `over` is true when a downgrade left more than the plan allows: nothing was removed, and the
 * next one will be refused.
 */
final readonly class GetWorkspacePlan
{
    public function __construct(
        private EntitlementStore $store,
        private UsageReader $usage,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    public function __invoke(): WorkspacePlanView
    {
        $this->guard->ensure(Permission::WorkspacesUpdate);

        return $this->forWorkspace($this->actor->workspaceId()->value);
    }

    /** The same view for a named workspace, for the platform console, which authorises its caller itself. */
    public function forWorkspace(string $workspaceId): WorkspacePlanView
    {
        $entitlements = $this->store->forWorkspace($workspaceId);
        $now = $this->clock->now();

        $features = array_map(static fn (Feature $f): array => [
            'key' => $f->value,
            'enabled' => $entitlements->has($f),
            'source' => $f->isEverywhere() ? 'everywhere' : ($entitlements->featureSource[$f->value] ?? 'plan'),
            'everywhere' => $f->isEverywhere(),
        ], Feature::cases());

        $limits = array_map(function (Limit $l) use ($entitlements, $workspaceId, $now): array {
            $allowed = $entitlements->limit($l);
            $used = $this->usage->used($workspaceId, $l, $now);

            return [
                'key' => $l->value,
                'allowed' => $allowed,
                'used' => $used,
                'source' => $entitlements->limitSource[$l->value] ?? 'plan',
                'monthly' => $l->isMonthly(),
                'resets_at' => $l->isMonthly() ? EntitlementGuard::nextMonth($now) : null,
                'over' => $allowed !== null && $used !== null && $used > $allowed,
                'refuses' => $l->refusesWhenExhausted(),
            ];
        }, Limit::cases());

        return new WorkspacePlanView(
            planCode: $entitlements->planCode,
            planName: $entitlements->planName,
            features: $features,
            limits: $limits,
            overrides: $this->store->overrides($workspaceId),
        );
    }
}
