<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\CampaignCreator;
use App\Application\Campaign\Contract\LaunchQueue;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Application\Campaign\Exception\BlueprintRefused;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Launch\ExecutionStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * After an unknown outcome: look on the platform for what that request may have created, attach it if it exists, and say plainly
 * when nothing was created — the only state from which a retry is allowed. A retry reuses the same idempotency reference, so the
 * platform (where it de-duplicates) and the product both recognise it as the same request.
 */
final readonly class RecoverExecution
{
    public function __construct(
        private LaunchRepository $launches,
        private CampaignCreator $creator,
        private ExecuteCreation $execute,
        private LaunchQueue $queue,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
    ) {}

    public function reconcile(string $executionId): ExecutionRecord
    {
        $this->guard->ensure(Permission::CampaignLaunchExecute);
        $execution = $this->load($executionId);

        if ($execution->status !== ExecutionStatus::Unknown) {
            throw BlueprintRefused::notReconcilable($execution->status->value);
        }

        [$request, $plan, $name] = $this->execute->request($execution);
        $found = $request === null ? null : $this->creator->find($request);

        if ($request !== null && $found !== null) {
            $this->execute->apply($execution, $request, $plan, $name, $found, $this->actor->userId()->value);
        } else {
            $this->launches->updateExecution($execution->workspaceId, $execution->id, [
                'status' => ExecutionStatus::Failed->value,
                'failure_classification' => 'not_created',
                'message' => 'Checked on the platform: nothing was created by this request. It can be tried again.',
            ]);
        }

        $this->record(AuditAction::CampaignLaunchReconciled, $execution, $found === null ? 'nothing found; may be retried' : 'found and attached');

        return $this->load($executionId);
    }

    public function retry(string $executionId): ExecutionRecord
    {
        $this->guard->ensure(Permission::CampaignLaunchExecute);
        $execution = $this->load($executionId);

        if (! $execution->status->mayRetry()) {
            throw BlueprintRefused::notRetryable($execution->status->value);
        }

        $this->launches->updateExecution($execution->workspaceId, $execution->id, ['status' => ExecutionStatus::Queued->value, 'failure_classification' => null, 'message' => null]);
        $this->record(AuditAction::CampaignLaunchRetried, $execution, sprintf('attempt %d', $execution->attempt + 1));
        $this->queue->push($execution->workspaceId, $execution->id, $this->actor->userId()->value);

        return $this->load($executionId);
    }

    private function load(string $id): ExecutionRecord
    {
        return $this->launches->execution($this->actor->workspaceId()->value, $id) ?? throw ResourceNotFound::of('Launch execution');
    }

    private function record(AuditAction $action, ExecutionRecord $execution, string $what): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'campaign_launch_execution',
            auditableId: $execution->id,
            summary: sprintf('%s on %s: %s.', $action === AuditAction::CampaignLaunchRetried ? 'Retried the creation' : 'Reconciled an unknown creation', $execution->provider, $what),
            changes: ['idempotency_reference' => $execution->idempotencyReference, 'source' => $execution->source ?? MetricSource::Live->value],
        ));
    }
}
