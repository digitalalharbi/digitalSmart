<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\CampaignActivator;
use App\Application\Campaign\Contract\CampaignReconciler;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\Launch\ActivationOutcome;
use App\Domain\Advertising\Launch\ActivationStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
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

        if ($request === null) {
            $outcome = new ActivationOutcome(ActivationStatus::Failed, MetricSource::Live, [], 'The ad account is no longer available to this project; left paused.');
        } else {
            try {
                $outcome = $this->activator->activate($request, $execution->providerCampaignId, $execution->providerAdGroupId, $execution->providerAdIds);
            } catch (Throwable $e) {
                $outcome = new ActivationOutcome(ActivationStatus::Failed, $request->sandbox ? MetricSource::Sandbox : MetricSource::Live, [], 'The platform did not answer; check it before trying again: '.mb_substr($e->getMessage(), 0, 200));
            }
        }

        $fields = ['activation_status' => $outcome->status->value, 'activation_message' => $outcome->message];

        if ($outcome->status === ActivationStatus::Active) {
            $fields += ['activated_at' => $this->clock->now(), 'activation_readback' => json_encode($outcome->observed, JSON_THROW_ON_ERROR)];
            $this->reconciler->markActive($execution);
        }

        $this->launches->updateExecution($workspaceId, $executionId, $fields);
        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($workspaceId),
            actorId: new UserId($approvedBy),
            action: AuditAction::CampaignActivationExecuted,
            auditableType: 'campaign_launch_execution',
            auditableId: $executionId,
            summary: sprintf('%s on %s: %s%s.', $name, $plan->provider->label(), str_replace('_', ' ', $outcome->status->value), $outcome->source === MetricSource::Sandbox ? ' (SANDBOX)' : ''),
            changes: ['provider' => $plan->provider->value, 'status' => $outcome->status->value, 'source' => $outcome->source->value, 'campaign' => $execution->providerCampaignId],
        ));

        $this->progress->settle($workspaceId, $execution->blueprintId);
    }
}
