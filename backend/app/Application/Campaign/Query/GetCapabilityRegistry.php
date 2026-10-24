<?php

declare(strict_types=1);

namespace App\Application\Campaign\Query;

use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Integration\Capability\CapabilityKey;
use App\Domain\Integration\Capability\ProviderCapabilityRegistry;
use App\Domain\Integration\Provider;

/** The capability registry, for a person planning a campaign or looking after the workspace's connections. */
final readonly class GetCapabilityRegistry
{
    public function __construct(private AuthorizationGuard $guard) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        $this->guard->ensureAny(Permission::CampaignBlueprintsView, Permission::WorkspacesView);

        return array_map(static function (Provider $provider): array {
            $profile = ProviderCapabilityRegistry::profile($provider);

            return [
                'provider' => $provider->value,
                'api_version' => $profile['api'] ?? null,
                'paused_on_create' => $profile['paused'] ?? null,
                'delivers_by_default' => $profile['delivers_by_default'] ?? false,
                'access' => $profile['access'] ?? null,
                'access_reference' => $profile['access_ref'] ?? null,
                'capabilities' => array_map(static function (CapabilityKey $key) use ($provider): array {
                    $e = ProviderCapabilityRegistry::evidence($provider, $key);

                    return ['key' => $key->value, 'state' => $e->state->value, 'reference' => $e->reference, 'verified_on' => $e->verifiedOn, 'note' => $e->note];
                }, CapabilityKey::cases()),
            ];
        }, ProviderCapabilityRegistry::providers());
    }
}
