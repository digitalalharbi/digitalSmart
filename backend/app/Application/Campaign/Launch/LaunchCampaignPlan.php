<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\Contract\LaunchQueue;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\Exception\BlueprintRefused;
use App\Application\Campaign\Query\BuildLaunchPlan;
use App\Application\Campaign\Query\ReadBlueprints;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Launch\ExecutionStatus;
use App\Domain\Advertising\Launch\PreflightCheck;
use App\Domain\Advertising\Launch\PreflightState;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Capability\CapabilityKey;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Domain\Saas\Feature;
use App\Domain\Saas\Limit;

/**
 * Start creating an approved plan on its platforms — paused, queued, one account at a time.
 *
 * The approval must still name what would be written: the launch plan is rebuilt and fingerprinted, and a mismatch sends the plan
 * back to review instead of creating something nobody approved. Each account then gets an execution record with a stable
 * idempotency reference: queued where it can be attempted, `skipped` where the preflight stops it, `blocked_external` where only
 * the platform can unblock it. Nothing runs in this request.
 */
final readonly class LaunchCampaignPlan
{
    public function __construct(
        private BlueprintRepository $blueprints,
        private ReadBlueprints $read,
        private BuildLaunchPlan $launchPlan,
        private LaunchRepository $launches,
        private LaunchQueue $queue,
        private LaunchProgress $progress,
        private AuthorizationGuard $guard,
        private EntitlementGuard $entitlements,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private IdentifierGenerator $ids,
    ) {}

    /** @return array{launch_id: string, queued: int, skipped: int, blocked_external: int} */
    public function __invoke(string $id): array
    {
        $this->guard->ensure(Permission::CampaignLaunchExecute);
        $this->entitlements->ensureFeature(Feature::CampaignLaunch);

        $workspace = $this->actor->workspaceId()->value;
        $plan = $this->read->get($id)['blueprint'];

        if ($plan->status !== BlueprintStatus::Approved->value) {
            throw BlueprintRefused::notApproved();
        }

        $launch = ($this->launchPlan)($id);
        $approval = $this->blueprints->approval($workspace, $id);

        if ($approval['fingerprint'] !== ApproveCampaignPlan::fingerprint($launch)) {
            $this->blueprints->withdrawApproval($workspace, $id, 'The plan, or what it would write, changed after approval.');

            throw BlueprintRefused::approvalStale();
        }

        $blocked = ApproveCampaignPlan::blockedChecks($launch);

        if ($blocked !== []) {
            throw BlueprintRefused::preflightBlocked($blocked);
        }

        $launchId = $this->ids->generate();
        $executor = $this->actor->userId()->value;
        $queued = [];
        $counts = ['queued' => 0, 'skipped' => 0, 'blocked_external' => 0];

        $this->entitlements->claim(Limit::CampaignLaunchesMonthly, function () use ($launch, $launchId, $plan, $workspace, $executor, &$queued, &$counts, $approval): void {
            $this->launches->createLaunch(
                $launchId, $workspace, $plan->clientId, $plan->projectId, $plan->id, $launch['version'], (string) $approval['fingerprint'],
                ['name' => $plan->name, 'providers' => array_map(static fn (array $p): array => $p['plan']->toArray(), $launch['providers'])],
                null, null, $executor,
            );

            foreach ($launch['providers'] as $entry) {
                $createPlan = $entry['plan'];
                [$status, $message] = self::decide($entry['checks']);
                $executionId = $this->ids->generate();
                $this->launches->createExecution(
                    $executionId, $workspace, $launchId, $createPlan->provider->value, $createPlan->externalAccountId,
                    hash('sha256', $launchId.'|'.$createPlan->externalAccountId),
                    $status, $message,
                    ProviderCapabilityRegistry::evidence($createPlan->provider, CapabilityKey::CampaignCreate)->reference,
                );
                match ($status) {
                    ExecutionStatus::Queued => $counts['queued']++,
                    ExecutionStatus::Skipped => $counts['skipped']++,
                    default => $counts['blocked_external']++,
                };

                if ($status === ExecutionStatus::Queued) {
                    $queued[] = $executionId;
                }
            }
        });

        $this->blueprints->setStatus($workspace, $id, BlueprintStatus::Queued, null, null);
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CampaignLaunchStarted,
            auditableType: 'campaign_blueprint',
            auditableId: $id,
            summary: sprintf('Started creating "%s" paused: %d queued, %d skipped, %d waiting on the platform.', $plan->name, $counts['queued'], $counts['skipped'], $counts['blocked_external']),
            changes: ['launch_id' => $launchId, 'fingerprint' => $approval['fingerprint'], 'version' => $launch['version'], ...$counts],
        ));

        foreach ($queued as $executionId) {
            $this->queue->push($workspace, $executionId, $executor);
        }

        if ($queued === []) {
            $this->progress->settle($workspace, $id);
        }

        return ['launch_id' => $launchId, 'queued' => $counts['queued'], 'skipped' => $counts['skipped'], 'blocked_external' => $counts['blocked_external']];
    }

    /**
     * @param  list<PreflightCheck>  $checks
     * @return array{0: ExecutionStatus, 1: string|null}
     */
    private static function decide(array $checks): array
    {
        foreach ($checks as $check) {
            if (in_array($check->state, [PreflightState::Blocked, PreflightState::Unsupported], true)) {
                return [ExecutionStatus::Skipped, $check->message];
            }
        }

        foreach ($checks as $check) {
            if ($check->state === PreflightState::BlockedExternal) {
                return [ExecutionStatus::BlockedExternal, $check->message.' '.$check->fix];
            }
        }

        return [ExecutionStatus::Queued, null];
    }
}
