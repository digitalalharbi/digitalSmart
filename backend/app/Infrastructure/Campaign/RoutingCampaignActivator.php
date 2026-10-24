<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\CampaignActivator;
use App\Application\Campaign\DTO\CreationRequest;
use App\Domain\Advertising\Launch\ActivationOutcome;
use App\Domain\Advertising\Launch\ActivationStatus;
use App\Domain\Advertising\MetricSource;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;

/**
 * Activation, routed like creation: a sandbox connection is switched on in the sandbox and labelled SANDBOX; real credentials are
 * recorded `blocked_external` and nothing is called, because no live activation adapter exists and the platform's write access
 * has not been confirmed. The sandbox reports the campaign active because it was asked to — a live adapter must read the platform.
 */
final readonly class RoutingCampaignActivator implements CampaignActivator
{
    public function activate(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ActivationOutcome
    {
        if (! $request->sandbox) {
            return new ActivationOutcome(ActivationStatus::BlockedExternal, MetricSource::Live, [], sprintf(
                'No live activation adapter exists for %s yet; nothing was switched on. Access required: %s',
                $request->plan->provider->label(),
                ProviderCapabilityRegistry::profile($request->plan->provider)['access'] ?? 'the platform\'s write access',
            ));
        }

        return new ActivationOutcome(ActivationStatus::Active, MetricSource::Sandbox, [
            'campaign_id' => $campaignId,
            'ad_group_id' => $adGroupId,
            'ad_ids' => $adIds,
            'status' => 'ACTIVE',
            'source' => MetricSource::Sandbox->value,
        ], null);
    }
}
