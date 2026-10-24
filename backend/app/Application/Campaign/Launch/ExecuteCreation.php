<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\Contract\CampaignCreator;
use App\Application\Campaign\Contract\CampaignReconciler;
use App\Application\Campaign\Contract\LaunchFacts;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\DTO\CreationRequest;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Launch\CreateOutcome;
use App\Domain\Advertising\Launch\ExecutionStatus;
use App\Domain\Advertising\Launch\ProviderCreatePlan;
use App\Domain\Advertising\MetricSource;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One account's creation, run by a worker: create paused, read back, and only then verified.
 *
 * **HTTP 200 is not verified.** A platform answering with ids moves the record to `created`; it is `verified` only when the objects
 * are read back and match the plan, and only a verified creation is put into the product's own campaign tables. A request that
 * fails without an answer is `unknown`, never `failed` — it may have created something, so it is never retried until reconciled.
 */
final readonly class ExecuteCreation
{
    public function __construct(
        private LaunchRepository $launches,
        private LaunchFacts $facts,
        private CampaignCreator $creator,
        private CampaignReconciler $reconciler,
        private LaunchProgress $progress,
        private AuditRecorder $audit,
        private Clock $clock,
        private BlueprintRepository $blueprints,
        private LoggerInterface $log,
    ) {}

    public function __invoke(string $workspaceId, string $executionId, string $executedBy): void
    {
        $execution = $this->launches->execution($workspaceId, $executionId);

        /* Idempotent: a worker that picks the same job up twice finds it already under way, or done, and leaves it. */
        if ($execution === null || $execution->status !== ExecutionStatus::Queued) {
            return;
        }

        [$request, $plan, $name] = $this->request($execution);

        if ($request === null) {
            if ($this->launches->transition($workspaceId, $executionId, ExecutionStatus::Queued, [
                'status' => ExecutionStatus::Failed->value,
                'failure_classification' => 'account_unavailable',
                'message' => 'The ad account is no longer available to this project.',
                'request_finished_at' => $this->clock->now(),
            ])) {
                $this->progress->settle($workspaceId, $execution->blueprintId);
            }

            return;
        }

        /* One statement decides who creates: of two workers holding this job, exactly one moves queued → creating. */
        $claimed = $this->launches->transition($workspaceId, $executionId, ExecutionStatus::Queued, [
            'status' => ExecutionStatus::Creating->value,
            'attempt' => $execution->attempt + 1,
            'source' => $request->sandbox ? MetricSource::Sandbox->value : MetricSource::Live->value,
            'request_started_at' => $this->clock->now(),
        ]);

        if (! $claimed) {
            return;
        }

        $this->blueprints->setStatus($workspaceId, $execution->blueprintId, BlueprintStatus::Creating, null, null);

        try {
            $outcome = $this->creator->create($request);
        } catch (Throwable $e) {
            /* No answer is not a failure: something may exist on the platform now. */
            $outcome = CreateOutcome::unknown($request->sandbox ? MetricSource::Sandbox : MetricSource::Live, 'The platform did not answer: '.mb_substr($e->getMessage(), 0, 300));
        }

        $this->apply($execution, $request, $plan, $name, $outcome, $executedBy);
    }

    /**
     * Record an outcome, read back what was created, and reconcile it. Shared with recovery, so a reconciled creation is verified
     * exactly like a first one.
     */
    public function apply(ExecutionRecord $execution, CreationRequest $request, ProviderCreatePlan $plan, string $name, CreateOutcome $outcome, string $executedBy): void
    {
        $fields = [
            'status' => $outcome->status->value,
            'source' => $outcome->source->value,
            'failure_classification' => $outcome->classification,
            'message' => $outcome->message,
            'request_finished_at' => $this->clock->now(),
        ];
        $readback = null;

        /* A "created" answer with no ids names nothing to read back or find again: treat it as not known, never as done. */
        if ($outcome->status === ExecutionStatus::Created && ($outcome->campaignId === null || $outcome->adGroupId === null)) {
            $fields['status'] = ExecutionStatus::Unknown->value;
            $fields['failure_classification'] = 'unknown';
            $fields['message'] = 'The platform said it created something but returned no ids. Check the account before trying again.';
        } elseif ($outcome->status === ExecutionStatus::Created) {
            /* The ids are written first and whatever read-back does cannot lose them: something exists on the platform now. */
            $fields += [
                'provider_campaign_id' => $outcome->campaignId,
                'provider_ad_group_id' => $outcome->adGroupId,
                'provider_ad_ids' => json_encode($outcome->adIds, JSON_THROW_ON_ERROR),
            ];

            try {
                $readback = $this->creator->readBack($request, (string) $outcome->campaignId, (string) $outcome->adGroupId, $outcome->adIds);
                $fields['readback'] = json_encode(['found' => $readback->found, 'observed' => $readback->observed, 'mismatches' => $readback->mismatches], JSON_THROW_ON_ERROR);
                $fields['status'] = $readback->matches() ? ExecutionStatus::Verified->value : ExecutionStatus::ReadbackMismatch->value;

                if (! $readback->matches()) {
                    $fields['message'] = 'Created, and the platform reports something other than the plan: '.implode('; ', $readback->mismatches);
                }
            } catch (Throwable $e) {
                /* Created but unverified. Not retryable (it exists), not verified (nobody read it), not left "creating". */
                $fields['status'] = ExecutionStatus::ReadbackMismatch->value;
                $fields['failure_classification'] = 'readback_failed';
                $fields['message'] = 'Created on the platform, but reading it back failed, so it is not verified: '.mb_substr($e->getMessage(), 0, 300);
            }
        }

        $this->launches->updateExecution($execution->workspaceId, $execution->id, $fields);
        $updated = $this->launches->execution($execution->workspaceId, $execution->id);

        if ($updated !== null && $updated->status === ExecutionStatus::Verified) {
            try {
                $this->reconciler->reconcile($updated, $plan, $name, $outcome->source);
            } catch (Throwable $e) {
                /* Verified on the platform is a fact; failing to copy it into the product's tables must not hide it or the audit. */
                $fields['message'] = 'Verified on the platform, but recording it in the campaign tables failed: '.mb_substr($e->getMessage(), 0, 300);
                $this->launches->updateExecution($execution->workspaceId, $execution->id, ['message' => $fields['message']]);
            }
        }

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($execution->workspaceId),
            actorId: new UserId($executedBy),
            action: AuditAction::CampaignLaunchExecuted,
            auditableType: 'campaign_launch_execution',
            auditableId: $execution->id,
            summary: sprintf('%s on %s: %s%s.', $name, $plan->provider->label(), str_replace('_', ' ', (string) $fields['status']), $outcome->source === MetricSource::Sandbox ? ' (SANDBOX)' : ''),
            changes: [
                'provider' => $plan->provider->value,
                'external_account_id' => $plan->externalAccountId,
                'status' => $fields['status'],
                'source' => $outcome->source->value,
                'provider_ids' => ['campaign' => $outcome->campaignId, 'ad_group' => $outcome->adGroupId, 'ads' => $outcome->adIds],
                'readback' => $readback === null ? null : ['found' => $readback->found, 'mismatches' => $readback->mismatches],
                'classification' => $outcome->classification,
                'idempotency_reference' => $execution->idempotencyReference,
            ],
        ));

        /* Operators see launch problems without opening a workspace: ids and states only, never a name, a credential or a figure. */
        if (in_array($fields['status'], [ExecutionStatus::Failed->value, ExecutionStatus::Unknown->value, ExecutionStatus::ReadbackMismatch->value, ExecutionStatus::BlockedExternal->value], true)) {
            $this->log->warning('campaign_launch.execution_not_verified', [
                'workspace_id' => $execution->workspaceId,
                'execution_id' => $execution->id,
                'provider' => $plan->provider->value,
                'status' => $fields['status'],
                'classification' => $outcome->classification,
                'source' => $outcome->source->value,
            ]);
        }

        $this->progress->settle($execution->workspaceId, $execution->blueprintId);
    }

    /** @return array{0: CreationRequest|null, 1: ProviderCreatePlan, 2: string} */
    public function request(ExecutionRecord $execution): array
    {
        $snapshot = $this->launches->snapshot($execution->workspaceId, $execution->launchId);
        $entry = null;

        foreach ((array) ($snapshot['providers'] ?? []) as $provider) {
            if (is_array($provider) && ($provider['external_account_id'] ?? null) === $execution->externalAccountId) {
                $entry = $provider;
            }
        }

        $plan = ProviderCreatePlan::fromArray((array) $entry);
        $name = (string) ($snapshot['name'] ?? 'Campaign');
        $account = $this->facts->account($execution->workspaceId, $execution->projectId, $execution->externalAccountId);

        if ($account === null || ! $account->boundToProject) {
            return [null, $plan, $name];
        }

        return [new CreationRequest($execution->workspaceId, $name, $account->platformAccountId, $execution->idempotencyReference, $plan, $account->sandbox), $plan, $name];
    }
}
