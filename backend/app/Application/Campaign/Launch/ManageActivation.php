<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\Contract\LaunchFacts;
use App\Application\Campaign\Contract\LaunchQueue;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\Exception\BlueprintRefused;
use App\Application\Campaign\Query\ReadBlueprints;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Launch\ActivationStatus;
use App\Domain\Advertising\Launch\ExecutionStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Capability\CapabilityKey;
use App\Domain\Integration\Capability\CapabilityState;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Domain\Integration\Provider;
use App\Domain\Saas\Feature;

/**
 * Switching created campaigns on, as two acts: somebody asks, and somebody with `campaign_launch.activate` approves — then a worker
 * activates each account and reads it back. Only verified creations can be switched on; an account whose platform does not
 * document activation, or that is no longer bound to the project, is refused with the reason and left paused.
 */
final readonly class ManageActivation
{
    public function __construct(
        private ReadBlueprints $read,
        private BlueprintRepository $blueprints,
        private LaunchRepository $launches,
        private LaunchFacts $facts,
        private LaunchQueue $queue,
        private ActivationProgress $progress,
        private AuthorizationGuard $guard,
        private EntitlementGuard $entitlements,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function request(string $id): void
    {
        $this->guard->ensure(Permission::CampaignLaunchExecute);
        $plan = $this->read->get($id)['blueprint'];

        if (! BlueprintStatus::from($plan->status)->canMoveTo(BlueprintStatus::ActivationPending)) {
            throw BlueprintRefused::cannotMove($plan->status, BlueprintStatus::ActivationPending->value);
        }

        $workspace = $this->actor->workspaceId()->value;

        foreach ($this->verified($id) as $execution) {
            $this->launches->updateExecution($workspace, $execution->id, [
                'activation_status' => ActivationStatus::Pending->value,
                'activation_requested_by' => $this->actor->userId()->value,
                'activation_message' => null,
            ]);
        }

        $this->blueprints->setStatus($workspace, $id, BlueprintStatus::ActivationPending, null, null);
        $this->record(AuditAction::CampaignActivationRequested, $id, sprintf('Asked to switch on "%s".', $plan->name), []);
    }

    /** @return array{queued: int, refused: int} */
    public function approve(string $id): array
    {
        $this->guard->ensure(Permission::CampaignLaunchActivate);
        $this->entitlements->ensureFeature(Feature::CampaignLaunch);
        $plan = $this->read->get($id)['blueprint'];

        if ($plan->status !== BlueprintStatus::ActivationPending->value) {
            throw BlueprintRefused::cannotMove($plan->status, BlueprintStatus::Active->value);
        }

        $workspace = $this->actor->workspaceId()->value;
        $counts = ['queued' => 0, 'refused' => 0];
        $queue = [];

        foreach ($this->verified($id) as $execution) {
            if (! in_array($execution->activationStatus, [ActivationStatus::Pending, ActivationStatus::Failed], true)) {
                continue;
            }

            $provider = Provider::from($execution->provider);
            $account = $this->facts->account($workspace, $plan->projectId, $execution->externalAccountId);
            $refusal = match (true) {
                ProviderCapabilityRegistry::evidence($provider, CapabilityKey::CampaignActivate)->state !== CapabilityState::Documented => sprintf('%s’s documentation does not confirm activation; left paused.', $provider->label()),
                $account === null || ! $account->boundToProject => 'The ad account is no longer bound to this project; left paused.',
                default => null,
            };

            if ($refusal !== null) {
                $this->launches->updateExecution($workspace, $execution->id, ['activation_status' => ActivationStatus::Failed->value, 'activation_message' => $refusal]);
                $counts['refused']++;

                continue;
            }

            /* Two approvals at once queue one job: only the caller that moves it out of pending/failed goes on to push it. */
            if (! $this->launches->transitionActivation($workspace, $execution->id, $execution->activationStatus ?? ActivationStatus::Pending, [
                'activation_status' => ActivationStatus::Activating->value,
                'activation_approved_by' => $this->actor->userId()->value,
                'activation_message' => null,
            ])) {
                continue;
            }

            $queue[] = $execution->id;
            $counts['queued']++;
        }

        $this->record(AuditAction::CampaignActivationApproved, $id, sprintf('Approved switching on "%s": %d account(s) queued, %d left paused.', $plan->name, $counts['queued'], $counts['refused']), $counts);

        foreach ($queue as $executionId) {
            $this->queue->pushActivation($workspace, $executionId, $this->actor->userId()->value);
        }

        if ($queue === []) {
            $this->progress->settle($workspace, $id);
        }

        return $counts;
    }

    /** @return list<\App\Application\Campaign\DTO\ExecutionRecord> */
    private function verified(string $id): array
    {
        $all = $this->launches->executionsForBlueprint($this->actor->workspaceId()->value, $id);
        $latest = $all[0]->launchId ?? null;

        return array_values(array_filter($all, static fn ($e): bool => $e->launchId === $latest && $e->status === ExecutionStatus::Verified));
    }

    /** @param array<string, mixed> $changes */
    private function record(AuditAction $action, string $id, string $summary, array $changes): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'campaign_blueprint',
            auditableId: $id,
            summary: $summary,
            changes: $changes,
        ));
    }
}
