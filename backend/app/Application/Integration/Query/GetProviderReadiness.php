<?php

declare(strict_types=1);

namespace App\Application\Integration\Query;

use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderCategory;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\ProviderMcp;
use App\Domain\Integration\ReadinessState;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Integration\WriteOperation;

/**
 * The provider readiness matrix: for each advertising platform, at each level, what its own documentation says it
 * lets an API read and change — and how far this product has proven each of those.
 *
 * Reads at every level and every documented change have run end to end against sandbox credentials (the sync tests
 * and the carry-out tests), so they stand at `sandbox_verified`; the next rung is a real connection. Nothing here
 * reports a live rung: that needs a real round trip seen in the product, and none has happened in this installation.
 * A change the platform does not document at a level is `not_offered`, with its reason; a bid is documented on every
 * ad group and wired nowhere, so it is `not_wired` — never an action.
 */
final readonly class GetProviderReadiness
{
    public function __construct(
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::WorkspacesView);

        $out = [];

        foreach (Provider::cases() as $provider) {
            if ($provider->category() !== ProviderCategory::Advertising || ! ProviderEntityWrites::read($provider)) {
                continue;
            }

            $connection = $this->connections->agencyConnection($this->actor->workspaceId(), $provider);
            $credentials = $connection === null ? 'none' : ($connection->credentials()->isSandbox($provider->requiredCredentialKeys()) ? 'sandbox' : 'real');
            /* The rung after the sandbox is a real connection; with one stored, it is the live adapter that is missing. */
            $next = $credentials === 'real' ? 'live_adapter_not_built' : ReadinessState::AwaitingCredentials->value;
            $reached = [ReadinessState::CodeVerified->value, ReadinessState::SandboxVerified->value];
            $mcp = ProviderMcp::of($provider);
            $levels = [];

            foreach ([AdEntityType::Campaign, AdEntityType::AdGroup, AdEntityType::Ad] as $level) {
                $changes = [];

                foreach (['pause', 'resume', 'budget'] as $change) {
                    $operation = WriteOperation::at($level, $change);

                    if ($operation === null) {
                        $changes[$change] = ['support' => 'not_offered', 'because' => 'No platform keeps a budget on an ad; it spends its ad group\'s.'];

                        continue;
                    }

                    $documented = $provider->writeSupport($operation)->allowsAttempt();
                    $changes[$change] = $documented
                        ? ['support' => 'documented', 'reference' => $provider->writeReference($operation), 'reached' => $reached, 'state' => ReadinessState::SandboxVerified->value, 'next' => $next]
                        : ['support' => 'not_offered', 'because' => ProviderEntityWrites::notOffered($provider, $operation)];
                }

                $bid = ProviderEntityWrites::bid($provider, $level);
                $changes['bid'] = $bid === null
                    ? ['support' => 'not_offered', 'because' => 'Bids are set on the ad group.']
                    : ['support' => 'not_wired', 'field' => $bid, 'because' => 'Documented by the platform; no bid write is built in this product, so no rule offers it.'];

                $levels[$level->value] = [
                    'read' => ['support' => 'documented', 'reached' => $reached, 'state' => ReadinessState::SandboxVerified->value, 'next' => $next],
                    'changes' => $changes,
                ];
            }

            $out[] = [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'credentials' => $credentials,
                'levels' => $levels,
                'mcp' => [
                    'official' => $mcp['trust'] === 'official',
                    'reads' => $mcp['reads'],
                    'writes' => $mcp['writes'],
                    'source' => $mcp['source'],
                    /* Writes never route through an MCP server: only the direct API path reads a change back. */
                    'write_route' => 'direct_api',
                ],
                'checked_on' => ProviderMcp::CHECKED_ON,
                'live_evidence' => null,
            ];
        }

        return $out;
    }
}
