<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\CampaignCreator;
use App\Application\Campaign\DTO\CreationRequest;
use App\Domain\Advertising\Launch\CreateOutcome;
use App\Domain\Advertising\Launch\ReadBack;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;

/**
 * Which creator handles an account. A sandbox connection goes to the sandbox creator, whose every outcome is labelled SANDBOX. A
 * connection with real credentials gets **`blocked_external` and no call at all**: no live creation adapter exists in this
 * product yet, and the platform's write access has never been confirmed by a live call. The live adapters are the remaining work,
 * and what they need is the platform's approval and credentials, not a design.
 */
final readonly class RoutingCampaignCreator implements CampaignCreator
{
    public function __construct(private SandboxCampaignCreator $sandbox) {}

    public function create(CreationRequest $request): CreateOutcome
    {
        if ($request->sandbox) {
            return $this->sandbox->create($request);
        }

        return CreateOutcome::blockedExternal(sprintf(
            'No live creation adapter exists for %s yet, and this product will not simulate a creation against real credentials. Access required: %s',
            $request->plan->provider->label(),
            ProviderCapabilityRegistry::profile($request->plan->provider)['access'] ?? 'the platform\'s write access',
        ));
    }

    public function readBack(CreationRequest $request, string $campaignId, string $adGroupId, array $adIds): ReadBack
    {
        return $request->sandbox
            ? $this->sandbox->readBack($request, $campaignId, $adGroupId, $adIds)
            : new ReadBack(false, [], ['no live read-back adapter']);
    }

    public function find(CreationRequest $request): ?CreateOutcome
    {
        return $request->sandbox ? $this->sandbox->find($request) : null;
    }
}
