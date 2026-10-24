<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\CampaignActivator;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Application\Campaign\Exception\BlueprintRefused;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Launch\ActivationOutcome;
use App\Domain\Advertising\Launch\ActivationStatus;
use Throwable;

/**
 * After an unknown switch-on: read the platform, change nothing, and say what it reports. Found switched on, it is confirmed like
 * any activation; reported still paused, it becomes a proven failure that may be approved again; anything else — the platform
 * cannot be asked, or does not say — leaves it unknown. Nothing here ever sends an activation.
 */
final readonly class ReconcileActivation
{
    public function __construct(
        private LaunchRepository $launches,
        private ExecuteCreation $creation,
        private CampaignActivator $activator,
        private ExecuteActivation $activation,
        private ActivationProgress $progress,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    public function __invoke(ExecutionRecord $execution): void
    {
        $this->guard->ensure(Permission::CampaignLaunchActivate);

        if ($execution->activationStatus !== ActivationStatus::Unknown || $execution->providerCampaignId === null || $execution->providerAdGroupId === null) {
            throw BlueprintRefused::notReconcilable('not unknown');
        }

        /* Only one check runs at a time: `sending` doubles as "someone is looking". */
        if (! $this->launches->transitionActivation($execution->workspaceId, $execution->id, ActivationStatus::Unknown, ['activation_status' => ActivationStatus::Sending->value])) {
            throw BlueprintRefused::notReconcilable('in progress');
        }

        [$request, $plan, $name] = $this->creation->request($execution);

        try {
            $outcome = $request === null
                ? new ActivationOutcome(ActivationStatus::Unknown, \App\Domain\Advertising\MetricSource::Live, [], 'The ad account is no longer available to this project, so the platform could not be checked.')
                : $this->activator->read($request, $execution->providerCampaignId, $execution->providerAdGroupId, $execution->providerAdIds);
        } catch (Throwable $e) {
            $outcome = new ActivationOutcome(ActivationStatus::Unknown, $request?->sandbox ? \App\Domain\Advertising\MetricSource::Sandbox : \App\Domain\Advertising\MetricSource::Live, [], 'The platform could not be checked, so it is still not known whether this was switched on: '.mb_substr($e->getMessage(), 0, 200));
        }

        /* Only a clean "not on" or "on" settles it; a blocked or in-flight answer is not a reading. */
        if (! in_array($outcome->status, [ActivationStatus::Active, ActivationStatus::Failed], true)) {
            $outcome = new ActivationOutcome(ActivationStatus::Unknown, $outcome->source, [], $outcome->message ?? 'The platform did not say whether this is switched on.');
        }

        $this->activation->settle($execution, $outcome, $plan->provider->value, $name, $this->actor->userId()->value);
        $this->progress->settle($execution->workspaceId, $execution->blueprintId);
    }
}
