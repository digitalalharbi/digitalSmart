<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\CampaignActivator;
use App\Application\Campaign\Contract\CampaignReconciler;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\Launch\ActivationOutcome;
use App\Domain\Advertising\Launch\ActivationStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
use App\Domain\Integration\Provider;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * One account's activation, run by a worker. Active only when the platform has been read back saying so; then — and only then —
 * the product's own campaign, ad group and ads for it read active too.
 */
final readonly class ExecuteActivation
{
    public function __construct(
        private LaunchRepository $launches,
        private ExecuteCreation $creation,
        private CampaignActivator $activator,
        private CampaignReconciler $reconciler,
        private ActivationProgress $progress,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function __invoke(string $workspaceId, string $executionId, string $approvedBy): void
    {
        $execution = $this->launches->execution($workspaceId, $executionId);

        if ($execution === null || $execution->activationStatus !== ActivationStatus::Activating
            || $execution->providerCampaignId === null || $execution->providerAdGroupId === null) {
            return;
        }

        [$request, $plan, $name] = $this->creation->request($execution);

        /* One statement decides who sends: of two workers holding this job, exactly one moves activating → sending. */
        if (! $this->launches->transitionActivation($workspaceId, $executionId, ActivationStatus::Activating, ['activation_status' => ActivationStatus::Sending->value])) {
            return;
        }

        if ($request === null) {
            $outcome = new ActivationOutcome(ActivationStatus::Failed, MetricSource::Live, [], 'The ad account is no longer available to this project; left paused. Nothing was sent.');
        } else {
            try {
                $outcome = $this->activator->activate($request, $execution->providerCampaignId, $execution->providerAdGroupId, $execution->providerAdIds);
            } catch (Throwable $e) {
                /* No answer is not a failure: the platform may have switched the campaign on, and it may now be spending. */
                $outcome = new ActivationOutcome(ActivationStatus::Unknown, $request->sandbox ? MetricSource::Sandbox : MetricSource::Live, [], 'The platform did not answer, so it is not known whether the campaign was switched on. Check it before anything is repeated: '.mb_substr($e->getMessage(), 0, 200));
            }
        }

        $this->settle($execution, $outcome, $plan->provider->value, $name, $approvedBy);
    }

    /**
     * Record an activation outcome. Shared with reconcile, so a campaign found switched on is confirmed exactly like a first
     * activation. **Active needs the platform's own observed state**: an answer that claims it with nothing observed is unknown.
     */
    public function settle(ExecutionRecord $execution, ActivationOutcome $outcome, string $provider, string $name, string $actorId): void
    {
        if ($outcome->status === ActivationStatus::Active && $outcome->observed === []) {
            $outcome = new ActivationOutcome(ActivationStatus::Unknown, $outcome->source, [], 'The platform was said to have switched it on, but nothing was read back to confirm it.');
        }

        $fields = ['activation_status' => $outcome->status->value, 'activation_message' => $outcome->message];

        if ($outcome->status === ActivationStatus::Active) {
            $fields += ['activated_at' => $this->clock->now(), 'activation_readback' => json_encode($outcome->observed, JSON_THROW_ON_ERROR)];
        }

        /* The record of what the platform said comes first; copying it into the product's tables cannot lose it. */
        $this->launches->updateExecution($execution->workspaceId, $execution->id, $fields);

        if ($outcome->status === ActivationStatus::Active) {
            try {
                $this->reconciler->markActive($execution);
            } catch (Throwable $e) {
                $this->launches->updateExecution($execution->workspaceId, $execution->id, ['activation_message' => 'Confirmed active on the platform, but recording it in the campaign tables failed: '.mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($execution->workspaceId),
            actorId: new UserId($actorId),
            action: AuditAction::CampaignActivationExecuted,
            auditableType: 'campaign_launch_execution',
            auditableId: $execution->id,
            summary: sprintf('%s on %s: %s%s.', $name, Provider::from($provider)->label(), str_replace('_', ' ', $outcome->status->value), $outcome->source === MetricSource::Sandbox ? ' (SANDBOX)' : ''),
            changes: ['provider' => $provider, 'status' => $outcome->status->value, 'source' => $outcome->source->value, 'campaign' => $execution->providerCampaignId],
        ));

        $this->progress->settle($execution->workspaceId, $execution->blueprintId);
    }
}
