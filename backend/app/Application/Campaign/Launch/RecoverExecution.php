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
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Launch\ActivationStatus;
use App\Domain\Advertising\Launch\ExecutionStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use Throwable;

/**
 * After an unknown outcome: look on the platform for what that request may have created, attach it if it exists, and say plainly
 * when nothing was created — the only state from which a retry is allowed. A retry reuses the same idempotency reference, so the
 * platform (where it de-duplicates) and the product both recognise it as the same request.
 */
final readonly class RecoverExecution
{
    /** A worker that died mid-request leaves `creating` behind; past this it is treated as an unknown outcome. */
    private const STALE_AFTER_SECONDS = 600;

    public function __construct(
        private LaunchRepository $launches,
        private CampaignCreator $creator,
        private ExecuteCreation $execute,
        private ReconcileActivation $activation,
        private LaunchQueue $queue,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function reconcile(string $executionId): ExecutionRecord
    {
        $this->guard->ensure(Permission::CampaignLaunchExecute);
        $execution = $this->load($executionId);

        /* A creation that is verified but whose switch-on is unknown is reconciled by reading the platform, never by creating. */
        if ($execution->activationStatus === ActivationStatus::Unknown) {
            ($this->activation)($execution);

            return $this->load($executionId);
        }

        if (! $this->mayReconcile($execution)) {
            throw BlueprintRefused::notReconcilable($execution->status->value);
        }

        /* Only one reconcile runs: `creating` doubles as "someone is checking", so a second press or a late worker stands down. */
        if (! $this->launches->transition($execution->workspaceId, $execution->id, $execution->status, [
            'status' => ExecutionStatus::Creating->value,
            'request_started_at' => $this->clock->now(),
        ])) {
            throw BlueprintRefused::notReconcilable('in progress');
        }

        try {
            [$request, $plan, $name] = $this->execute->request($execution);
            $found = $request === null ? false : $this->creator->find($request);
        } catch (Throwable $e) {
            /* The platform could not be asked. That proves nothing, so it goes back to unknown rather than to a retryable failure. */
            $this->launches->updateExecution($execution->workspaceId, $execution->id, [
                'status' => ExecutionStatus::Unknown->value,
                'failure_classification' => 'unknown',
                'message' => 'The platform could not be checked, so it is still not known whether this created anything: '.mb_substr($e->getMessage(), 0, 300),
            ]);
            $this->record(AuditAction::CampaignLaunchReconciled, $execution, 'could not check; still unknown');

            return $this->load($executionId);
        }

        if ($found === false) {
            /* No usable account any more: nothing can be looked up, so nothing is proven. */
            $this->launches->updateExecution($execution->workspaceId, $execution->id, [
                'status' => ExecutionStatus::Unknown->value,
                'failure_classification' => 'unknown',
                'message' => 'The ad account is no longer available to this project, so the platform could not be checked.',
            ]);
            $what = 'could not check; still unknown';
        } elseif ($found === null) {
            /* The only path to a retry: the platform was asked and holds nothing for this reference. */
            $this->launches->updateExecution($execution->workspaceId, $execution->id, [
                'status' => ExecutionStatus::Failed->value,
                'failure_classification' => 'not_created',
                'message' => 'Checked on the platform: nothing was created by this request. It can be tried again.',
            ]);
            $what = 'nothing found; may be retried';
        } elseif ($found->status === ExecutionStatus::Created) {
            $this->execute->apply($execution, $request, $plan, $name, $found, $this->actor->userId()->value);
            $what = 'found and attached';
        } else {
            /* An answer that is itself "unknown" or "failed" is not proof of absence. */
            $this->launches->updateExecution($execution->workspaceId, $execution->id, [
                'status' => ExecutionStatus::Unknown->value,
                'failure_classification' => 'unknown',
                'message' => $found->message ?? 'The platform could not be checked, so it is still not known whether this created anything.',
            ]);
            $what = 'could not check; still unknown';
        }

        $this->record(AuditAction::CampaignLaunchReconciled, $execution, $what);

        return $this->load($executionId);
    }

    public function retry(string $executionId): ExecutionRecord
    {
        $this->guard->ensure(Permission::CampaignLaunchExecute);
        $execution = $this->load($executionId);

        if (! $execution->status->mayRetry()) {
            throw BlueprintRefused::notRetryable($execution->status->value);
        }

        /* Two presses queue one job: only the caller that moves failed → queued goes on to push it. */
        if (! $this->launches->transition($execution->workspaceId, $execution->id, ExecutionStatus::Failed, ['status' => ExecutionStatus::Queued->value, 'failure_classification' => null, 'message' => null])) {
            throw BlueprintRefused::notRetryable('in progress');
        }

        $this->record(AuditAction::CampaignLaunchRetried, $execution, sprintf('attempt %d', $execution->attempt + 1));
        $this->queue->push($execution->workspaceId, $execution->id, $this->actor->userId()->value);

        return $this->load($executionId);
    }

    /** Unknown, or `creating` for so long that its worker must be gone. */
    public function mayReconcile(ExecutionRecord $execution): bool
    {
        if ($execution->activationStatus === ActivationStatus::Unknown) {
            return true;
        }

        if ($execution->status === ExecutionStatus::Unknown) {
            return true;
        }

        return $execution->status === ExecutionStatus::Creating
            && $execution->requestStartedAt !== null
            && $this->clock->now()->getTimestamp() - $execution->requestStartedAt->getTimestamp() > self::STALE_AFTER_SECONDS;
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
