<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\Exception\BlueprintRefused;
use App\Application\Campaign\Query\BuildLaunchPlan;
use App\Application\Campaign\Query\ReadBlueprints;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Launch\LaunchFingerprint;
use App\Domain\Advertising\Launch\PreflightCheck;
use App\Domain\Advertising\Launch\PreflightState;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Saas\Feature;

/**
 * Approve a plan for launch. The approval names a fingerprint of the plan's version and every write it would make; anything
 * that changes either afterwards — an edit, an account's currency, a platform's translation — voids it.
 *
 * Approving creates nothing. A plan with a check the workspace must fix first (`blocked`) cannot be approved; a platform awaiting
 * credentials or the platform's own access can, because those are fixed outside the plan and the launch re-checks them.
 */
final readonly class ApproveCampaignPlan
{
    public function __construct(
        private BlueprintRepository $blueprints,
        private ReadBlueprints $read,
        private BuildLaunchPlan $launchPlan,
        private AuthorizationGuard $guard,
        private EntitlementGuard $entitlements,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    /** @return array{fingerprint: string, version: int} */
    public function __invoke(string $id): array
    {
        $this->guard->ensure(Permission::CampaignLaunchApprove);
        $this->entitlements->ensureFeature(Feature::CampaignLaunch);

        $plan = $this->read->get($id)['blueprint'];

        if (! BlueprintStatus::from($plan->status)->canMoveTo(BlueprintStatus::Approved)) {
            throw BlueprintRefused::cannotMove($plan->status, BlueprintStatus::Approved->value);
        }

        $launch = ($this->launchPlan)($id);
        $blocked = self::blockedChecks($launch);

        if ($blocked !== []) {
            throw BlueprintRefused::preflightBlocked($blocked);
        }

        $fingerprint = self::fingerprint($launch);
        $this->blueprints->approve($this->actor->workspaceId()->value, $id, $this->actor->userId()->value, $fingerprint, $launch['version']);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CampaignLaunchApproved,
            auditableType: 'campaign_blueprint',
            auditableId: $id,
            summary: sprintf('Approved "%s" (version %d) for launch, created paused. Nothing was created by approving.', $plan->name, $launch['version']),
            changes: ['fingerprint' => $fingerprint, 'version' => $launch['version'], 'providers' => array_map(static fn (array $p): string => $p['plan']->provider->value, $launch['providers'])],
        ));

        return ['fingerprint' => $fingerprint, 'version' => $launch['version']];
    }

    /**
     * The fingerprint of a launch plan: its version and every planned write.
     *
     * @param  array{version: int, providers: list<array{plan: \App\Domain\Advertising\Launch\ProviderCreatePlan}>}  $launch
     */
    public static function fingerprint(array $launch): string
    {
        return LaunchFingerprint::of([
            'version' => $launch['version'],
            'writes' => array_map(static fn (array $p): array => $p['plan']->toArray(), $launch['providers']),
        ]);
    }

    /**
     * @param  array{checks: list<PreflightCheck>, providers: list<array{checks: list<PreflightCheck>}>}  $launch
     * @return list<string>
     */
    public static function blockedChecks(array $launch): array
    {
        $messages = [];

        foreach ([$launch['checks'], ...array_map(static fn (array $p): array => $p['checks'], $launch['providers'])] as $checks) {
            foreach ($checks as $check) {
                if ($check->state === PreflightState::Blocked) {
                    $messages[] = $check->message;
                }
            }
        }

        return array_values(array_unique($messages));
    }
}
